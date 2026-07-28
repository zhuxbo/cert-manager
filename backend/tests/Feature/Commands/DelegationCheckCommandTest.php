<?php

use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\SystemAlert;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    // 服务层 mock：命令两阶段调 probeValidity（探测）+ applyProbeOutcomeIfUnchanged（CAS 落库，
    // 返回 true=落库成功/false=快照失配跳过）。TOCTOU 用例用 partial mock 走真实 CAS SQL。
    $this->delegationService = Mockery::mock(CnameDelegationService::class);
    $this->app->instance(CnameDelegationService::class, $this->delegationService);
});

afterEach(function () {
    Mockery::close();
});

/** 造一条委托记录（默认有效、fail_count=0）。 */
function checkDelegationRow(User $user, array $overrides = []): CnameDelegation
{
    return CnameDelegation::factory()->create(array_merge(['user_id' => $user->id], $overrides));
}

/** 为用户造一张覆盖 $domain 的 active 证书（阻止无 active 证书删除分支）。 */
function checkActiveCert(User $user, string $domain): void
{
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    Cert::factory()->active()->create([
        'order_id' => $order->id,
        'common_name' => $domain,
        'alternative_names' => $domain,
        'issuer' => null, // 跳过 retrieved 事件 issuer 检查
    ]);
}

/** 捕获 NotificationCenter dispatch（计数 + 首个 intent）。 */
function checkCaptureCenter(): object
{
    $state = new class
    {
        public int $count = 0;

        public array $intents = [];
    };
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        $state->count++;
        $state->intents[] = $intent;
    });
    app()->instance(NotificationCenter::class, $mock);

    return $state;
}

// ── 既有 8 用例（逐条适配 probe/apply 重构 + post-apply gate）─────────────────

test('签名为 delegation:check', function () {
    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查完成')
        ->assertSuccessful();
});

test('有效委托记录标记为有效', function () {
    $user = User::factory()->create();
    checkDelegationRow($user);

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('valid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('有效')
        ->assertSuccessful();
});

test('无效委托且无活跃证书时删除（预置 fail_count=2 过 gate）', function () {
    $user = User::factory()->create();
    // valid=false：探测 invalid 且 fail_count=2 的行真实形态即 invalid（mock 落库不写库，造数补齐；
    // 条件删除带 valid=false 守卫）
    $delegation = checkDelegationRow($user, ['zone' => 'unused.com', 'fail_count' => 2, 'valid' => false]);

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('已删除')
        ->assertSuccessful();

    expect(CnameDelegation::find($delegation->id))->toBeNull();
});

test('无效委托但有活跃证书时保留（fail_count=0 <阈值 → 不派发，抖动 gate 反向断言）', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'active-domain.com', 'fail_count' => 0]);
    checkActiveCert($user, 'active-domain.com');

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')
        ->expectsOutputToContain('保留')
        ->assertSuccessful();

    expect(CnameDelegation::find($delegation->id))->not->toBeNull()
        ->and($state->count)->toBe(0); // post=1 <2 → 不派发
});

test('dry-run 模式不删除记录（预置 fail_count=2 使删除分支可达、由 dry-run 拦截）', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'unused.com', 'fail_count' => 2, 'valid' => false]);

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $this->artisan('delegation:check --dry-run')
        ->expectsOutputToContain('dry-run')
        ->assertSuccessful();

    expect(CnameDelegation::find($delegation->id))->not->toBeNull();
});

test('检查异常时记录错误并继续', function () {
    $user = User::factory()->create();
    checkDelegationRow($user);

    $this->delegationService->shouldReceive('probeValidity')
        ->once()
        ->andThrow(new Exception('DNS 查询超时'));

    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查异常')
        ->assertSuccessful();
});

test('连续失败次数超过阈值时只输出预警不派发用户通知', function () {
    $user = User::factory()->create(['email' => 'fail@example.com']);
    checkDelegationRow($user, ['zone' => 'failing.com', 'fail_count' => 5]);
    checkActiveCert($user, 'failing.com');

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')
        ->expectsOutputToContain('连续失败')
        ->assertSuccessful();

    // 周巡检只负责健康检查；用户通知由自动续签发起前的委托检查触发。
    expect($state->count)->toBe(0);
});

test('无委托记录时正常完成', function () {
    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查完成')
        ->assertSuccessful();
});

// ── 新增用例 ─────────────────────────────────────────────────────────────────

test('抖动 gate：invalid + 无 active + fail_count=0（post 1 <2）→ 不删、不派发', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'jitter.com', 'fail_count' => 0]);

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')->assertSuccessful();

    expect(CnameDelegation::find($delegation->id))->not->toBeNull() // 未达阈值不删
        ->and($state->count)->toBe(0);
});

test('gate 口径判别（post-apply）：invalid + active + 预置 fail_count=1 → 落库后=2 仍不派发', function () {
    $user = User::factory()->create(['email' => 'edge@example.com']);
    checkDelegationRow($user, ['zone' => 'edge.com', 'fail_count' => 1]);
    checkActiveCert($user, 'edge.com');

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('gate 口径判别（post-apply）：invalid + 无 active + 预置 fail_count=1 → 落库后=2 → 删除', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'edge-del.com', 'fail_count' => 1, 'valid' => false]);

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $this->artisan('delegation:check')->assertSuccessful();

    expect(CnameDelegation::find($delegation->id))->toBeNull(); // post=2 → 删
});

test('同 user 两条失效委托也不由周巡检派发通知', function () {
    $user = User::factory()->create(['email' => 'agg@example.com']);
    checkDelegationRow($user, ['zone' => 'a.com', 'fail_count' => 2]);
    checkDelegationRow($user, ['zone' => 'b.com', 'fail_count' => 2]);
    checkActiveCert($user, 'a.com');
    checkActiveCert($user, 'b.com');

    $this->delegationService->shouldReceive('probeValidity')->twice()->andReturn('invalid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->twice()->andReturn(true);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('熔断轮：≥5 条全 unreachable → 零落库/零删除/零通知 + SystemAlert 告警', function () {
    $user = User::factory()->create();
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = checkDelegationRow($user, ['zone' => "outage$i.com"])->id;
    }

    // 全 unreachable → 熔断；落库方法绝不被调（写库前拦截）
    $this->delegationService->shouldReceive('probeValidity')->times(5)->andReturn('unreachable');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->never();

    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldReceive('send')
        ->once()
        ->withArgs(function ($category, $title, $message, $details, $dedupeKey, $ttl, $fingerprint) {
            return $category === 'delegation_patrol'
                && $dedupeKey === 'delegation_patrol_outage'
                && $ttl === 504
                && $fingerprint === 'patrol_outage'
                && $details['total'] === 5
                && $details['unreachable'] === 5;
        })
        ->andReturn(true);
    $systemAlert->shouldReceive('clearDedupe')->never(); // 熔断轮不清键
    app()->instance(SystemAlert::class, $systemAlert);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')->assertSuccessful();

    // 零删除（5 条全在）+ 零通知
    expect(CnameDelegation::whereIn('id', $ids)->count())->toBe(5)
        ->and($state->count)->toBe(0);
});

test('未熔断 healthy 轮 → SystemAlert clearDedupe 复位（恢复清键契约）', function () {
    $user = User::factory()->create();
    checkDelegationRow($user);

    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('valid');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldReceive('clearDedupe')->once()->with('delegation_patrol_outage');
    $systemAlert->shouldReceive('send')->never();
    app()->instance(SystemAlert::class, $systemAlert);

    $this->artisan('delegation:check')->assertSuccessful();
});

test('小基数全 unreachable（<样本下限）不熔断，冻结层独立生效', function () {
    $user = User::factory()->create();
    checkDelegationRow($user, ['zone' => 'small.com']);

    // total=1 <5 → 不熔断；仍走落库（applyProbeOutcome unreachable 冻结）
    $this->delegationService->shouldReceive('probeValidity')->once()->andReturn('unreachable');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->once()->andReturn(true);

    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldReceive('clearDedupe')->once(); // 未熔断 → 清键
    $systemAlert->shouldReceive('send')->never();       // 不发熔断告警
    app()->instance(SystemAlert::class, $systemAlert);

    $this->artisan('delegation:check')->assertSuccessful();
});

// ── 增量熔断：阶段①滚动判定 + 提前终止（达样本下限即判，命中停探测）──────────

test('增量熔断：达样本下限后立即命中 → 提前终止本轮探测（不对满表逐条付满价）', function () {
    $user = User::factory()->create();
    $ids = [];
    for ($i = 0; $i < 6; $i++) {
        $ids[] = checkDelegationRow($user, ['zone' => "burst$i.com"])->id;
    }

    // 6 条全 unreachable：达 MIN_SAMPLE=5 即熔断 → 第 5 条后提前终止，第 6 条不再探测。
    // 收敛前（扫完再判）probeValidity 被调 6 次 → times(5) 期望失败=红。
    $this->delegationService->shouldReceive('probeValidity')->times(5)->andReturn('unreachable');
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->never(); // 熔断轮零落库

    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldReceive('send')
        ->once()
        ->withArgs(function ($category, $title, $message, $details) {
            // 提前终止 → partial 计数（只探测了 5 条），方向正确
            return $category === 'delegation_patrol'
                && $details['total'] === 5
                && $details['unreachable'] === 5;
        })
        ->andReturn(true);
    $systemAlert->shouldReceive('clearDedupe')->never();
    app()->instance(SystemAlert::class, $systemAlert);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')->assertSuccessful();

    // 6 条全在（零删除）+ 零通知
    expect(CnameDelegation::whereIn('id', $ids)->count())->toBe(6)
        ->and($state->count)->toBe(0);
});

test('增量熔断边界：占比未达阈值不提前终止 → 全量探测 + 正常落库（不误熔断）', function () {
    $user = User::factory()->create();
    for ($i = 0; $i < 6; $i++) {
        checkDelegationRow($user, ['zone' => "mix$i.com"]);
    }

    // 前 2 条 unreachable + 后 4 条 valid：任一检查点占比 ≤ 2/5=0.4 < 0.5 → 不熔断、全量探测
    $this->delegationService->shouldReceive('probeValidity')->times(6)
        ->andReturnValues(['unreachable', 'unreachable', 'valid', 'valid', 'valid', 'valid']);
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->times(6)->andReturn(true);

    $systemAlert = Mockery::mock(SystemAlert::class);
    $systemAlert->shouldReceive('send')->never();      // 未熔断
    $systemAlert->shouldReceive('clearDedupe')->once(); // healthy 轮清键
    app()->instance(SystemAlert::class, $systemAlert);

    $this->artisan('delegation:check')->assertSuccessful();
});

// ── TOCTOU CAS 守卫：partial mock 只桩 probeValidity，落库走真实 CAS SQL ────

test('TOCTOU：探测后落库前被并发写新鲜 valid → 陈旧 invalid 不覆盖、不删、不通知', function () {
    $user = User::factory()->create(['email' => 'toctou@example.com']);
    // 初始 last_checked_at=null（factory 缺省）、fail_count=1：陈旧 invalid 若落库则 post=2 达删除阈
    $delegation = checkDelegationRow($user, ['zone' => 'toctou.com', 'valid' => false, 'fail_count' => 1]);

    $service = Mockery::mock(CnameDelegationService::class)->makePartial();
    $service->shouldReceive('probeValidity')->once()->andReturnUsing(function () use ($delegation) {
        // 阶段①探测期间（=阶段②落库前窗口内）：ValidateCommand/手动检查写入新鲜结论 valid=true
        CnameDelegation::whereKey($delegation->id)->update([
            'valid' => true, 'fail_count' => 0, 'last_checked_at' => now(),
        ]);

        return 'invalid'; // 陈旧结论
    });
    $this->app->instance(CnameDelegationService::class, $service);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')
        ->expectsOutputToContain('本轮结论作废')
        ->assertSuccessful();

    $delegation->refresh();
    expect($delegation->valid)->toBeTrue()             // 新鲜 valid 未被陈旧 invalid 覆盖
        ->and($delegation->fail_count)->toBe(0)        // 未被误 +1
        ->and(CnameDelegation::find($delegation->id))->not->toBeNull() // 未被误删
        ->and($state->count)->toBe(0);                 // 无误报通知
});

test('CAS 命中（非 null 快照）：无并发写 → 真落库，fail_count DB 侧自增 + 通知照发', function () {
    $user = User::factory()->create(['email' => 'cas-hit@example.com']);
    $delegation = checkDelegationRow($user, [
        'zone' => 'cas-hit.com', 'valid' => true, 'fail_count' => 1,
        'last_checked_at' => now()->subDay(),
    ]);
    checkActiveCert($user, 'cas-hit.com');

    $service = Mockery::mock(CnameDelegationService::class)->makePartial();
    $service->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->app->instance(CnameDelegationService::class, $service);

    $state = checkCaptureCenter();

    $this->artisan('delegation:check')->assertSuccessful();

    $delegation->refresh();
    expect($delegation->valid)->toBeFalse()
        ->and($delegation->fail_count)->toBe(2)                      // DB 侧 LEAST(1+1,100)
        ->and($delegation->last_checked_at->isAfter(now()->subMinutes(5)))->toBeTrue() // 时间戳前移
        ->and($state->count)->toBe(0);                               // 周巡检不派发用户通知
});

test('CAS 命中（null 快照 <=> NULL）：从未检查过的行正常落库 + fail_count LEAST 封顶 100', function () {
    $user = User::factory()->create(['email' => 'cap@example.com']);
    // last_checked_at 缺省 null：`= NULL` 恒不成立会让新委托永不落库，必须 <=> NULL-safe
    $delegation = checkDelegationRow($user, ['zone' => 'cap.com', 'valid' => false, 'fail_count' => 100]);
    checkActiveCert($user, 'cap.com');

    $service = Mockery::mock(CnameDelegationService::class)->makePartial();
    $service->shouldReceive('probeValidity')->once()->andReturn('invalid');
    $this->app->instance(CnameDelegationService::class, $service);

    $this->artisan('delegation:check')->assertSuccessful();

    $delegation->refresh();
    expect($delegation->fail_count)->toBe(100)                 // LEAST(100+1,100) 封顶
        ->and($delegation->last_checked_at)->not->toBeNull();  // NULL 快照 CAS 命中并留痕
});

test('schedule 注册 delegation:check 为周一 07:00', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $event = collect($schedule->events())
        ->first(fn (Event $e) => str_contains((string) ($e->command ?? ''), 'delegation:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 7 * * 1'); // weeklyOn(1, '07:00')
})->group('database');

test('委托周巡检不再配置独立用户通知 Builder', function () {
    expect(config('notification.builders'))->not->toHaveKey('delegation_invalid');
});
