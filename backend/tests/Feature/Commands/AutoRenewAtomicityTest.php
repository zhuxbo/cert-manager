<?php

use App\Jobs\TaskJob;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Api\Api;
use App\Services\Order\AutoRenewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\CreatesTestData;

/**
 * O1（AutoRenew renew/reissue+pay 包外层事务）/ O2（余额预检 refresh 实时化）守门测试。
 *
 * 与既有 AutoRenewCommandTest（整档 mock Action::class）分档：mock 对事务包裹透明（空事务包 mock 调用
 * 无 DB 副作用，包不包事务都绿），无法证明原子回滚。本文件用容器真身 Action + 真实 charge，pay 段 charge
 * 真失败才能证明外层事务把「renew 已翻转的旧证书 + 扣费」一并回滚。余额一律经 withBalance()/createTestUser
 * （Fund 钩子产合规流水）建立，本文件显式登记 fundAuditGuardedTestPaths() → afterEach 自动跑资金 invariant。
 */
uses(CreatesTestData::class);

afterEach(function () {
    Mockery::close();
});

beforeEach(function () {
    $this->configureTestDelegationProxyDomain();

    // 延时 commit task 不真正执行（否则同步驱动会触发上游 commit）；同时便于断言其入队
    Queue::fake();

    // AutoRenewService（DNS 委托检查）放行，避免真实 DNS 查询
    $svc = Mockery::mock(AutoRenewService::class);
    $svc->shouldReceive('checkDelegationValidity')->andReturn(true);
    $this->app->instance(AutoRenewService::class, $svc);
});

/**
 * 造一个「active 即将到期」的可续费订单（真实续费所需最小 fixture）。
 * reuse_csr=0 → AutoRenew 走 csr_generate（本地生成，无上游）；validation_methods 含 delegation。
 * expires_at=now+3（节点 3 窗口内）→ 失败兜底通知 gate 通过。
 *
 * @return array{0: Order, 1: Cert}
 */
function makeRenewableOrder(User $user): array
{
    $product = Product::factory()->create([
        'status' => 1, 'renew' => 1, 'reissue' => 1, 'reuse_csr' => 0,
        'product_type' => Product::TYPE_SSL, 'validation_type' => 'dv',
        'validation_methods' => ['delegation', 'txt'],
        'common_name_types' => ['standard'], 'alternative_name_types' => ['standard'],
        'periods' => [12], 'standard_min' => 0, 'standard_max' => 1,
        'wildcard_min' => 0, 'wildcard_max' => 0, 'total_min' => 1, 'total_max' => 1,
        'refund_period' => 30,
    ]);
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '0.00', 'alternative_wildcard_price' => '0.00',
    ]);

    $domain = 'renew-'.uniqid().'.example.com';
    $order = Order::factory()->create([
        'user_id' => $user->id, 'product_id' => $product->id,
        'auto_renew' => true, 'period' => 12,
        'period_from' => now()->subYear(), 'period_till' => now()->addDays(10), // ≤15 → 续费
        'amount' => '100.00',
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'common_name' => $domain, 'alternative_names' => $domain,
        'standard_count' => 1, 'wildcard_count' => 0,
        'channel' => 'web', 'expires_at' => now()->addDays(3),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    return [$order, $cert];
}

/** 装一个捕获 NotificationCenter dispatch 的 mock，intents 按引用回填。 */
function captureAutoRenewIntents(array &$intents): void
{
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return null;
    });
    app()->instance(NotificationCenter::class, $mock);
}

/** 一次性并发耗尽余额：在 O1 外层事务内首个 Cert 建立时把余额扣光（原生 UPDATE 不经 Transaction 钩子）。 */
function depleteBalanceOnFirstCert(User $user): void
{
    $depleted = false;
    Cert::created(function (Cert $c) use ($user, &$depleted) {
        if ($depleted) {
            return;
        }
        $depleted = true;
        DB::table('users')->where('id', $user->id)->update(['balance' => '0.00']);
    });
}

test('O1 成功路径：renew+pay 原子提交 —— 旧证书 renewed、新单 pending、扣费流水在、延时 commit 入队', function () {
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    [$order, $cert] = makeRenewableOrder($user);

    $intents = [];
    captureAutoRenewIntents($intents);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    // 旧证书翻 renewed（续费终态化）
    expect($cert->fresh()->status)->toBe('renewed');

    // 新续费订单已建、latestCert 为 pending（扣费落 pending，未提交上游）
    $newOrder = Order::where('user_id', $user->id)->where('id', '!=', $order->id)->first();
    expect($newOrder)->not->toBeNull()
        ->and($newOrder->latestCert->status)->toBe('pending')
        ->and($newOrder->latestCert->channel)->toBe('auto');

    // 扣费流水（type=order，-100）落库；余额 1000→900
    $tx = Transaction::where('type', 'order')->where('transaction_id', $newOrder->id)->get();
    expect($tx)->toHaveCount(1)
        ->and((float) $tx->first()->amount)->toBe(-100.0)
        ->and($user->fresh()->balance)->toBe('900.00');

    // 延时 commit task 入队（tasks 队列）+ delay 落在随机分散窗口内（AutoRenew 分散上游）+ 成功不发通知
    Queue::assertPushed(TaskJob::class, function (TaskJob $job) {
        if ($job->queue !== config('queue.names.tasks')) {
            return false;
        }
        // createTask：$later = random_int(0,28800)，>0 时 delay = now()+($later+3) 落 [now+4, now+28803]；
        // $later==0（random 边界，概率 1/28801）无 delay 立即派发，亦为合法形态。断言时点 now() ≥ dispatch
        // 时点，故 offset 上界不越 28803（无需 slack）；下界 ≥0 即「派发在未来」。
        if ($job->delay === null) {
            return true;
        }
        $offset = $job->delay->getTimestamp() - now()->getTimestamp();

        return $offset >= 0 && $offset <= 28803;
    });
    expect($intents)->toBeEmpty();
});

test('O1 原子回滚：pay 段 charge 因并发耗尽余额失败 → 旧证书回滚保持 active、无孤儿、发兜底通知', function () {
    // 预检通过后、charge 锁 user 前，另一路请求把余额扣光（模拟并发）——原生 UPDATE 在 O1 外层事务内注入，
    // charge 锁内二次校验读到 0 → 失败 → 外层回滚（renew 翻转 + 注入的扣减一并回滚，余额复原、无孤儿 transaction）。
    // 直接验证 charge 失败触发整体回滚，杜绝 renewed 终态 + unpaid 孤儿。
    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    [$order, $cert] = makeRenewableOrder($user);

    $intents = [];
    captureAutoRenewIntents($intents);
    depleteBalanceOnFirstCert($user);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    // 旧证书回滚保持 active（未 renewed）
    expect($cert->fresh()->status)->toBe('active');
    // 无新续费订单残留
    expect(Order::where('user_id', $user->id)->where('id', '!=', $order->id)->count())->toBe(0);
    // 无 charge 流水残留、余额未变（注入的扣减随外层回滚一并撤销）
    expect(Transaction::where('type', 'order')->count())->toBe(0)
        ->and($user->fresh()->balance)->toBe('1000.00');
    // 兜底失败通知已发（进程未中断的可测代理）
    expect(collect($intents)->pluck('code')->all())->toContain('auto_renew_failed');
    // charge 失败 → 无 commit task
    Queue::assertNotPushed(TaskJob::class);
});

test('O1 reissue 分支原子回滚：charge 失败 → 旧证书回滚保持 active、reissue cert 不残留', function () {
    // 重签走 auto_reissue（订单剩余 >15 天 → reissue）；增域名单价>0 使 reissue 扣费>0，并发耗尽余额致 charge 失败。
    $user = $this->createTestUser([
        'balance' => '1000.00',
        'auto_settings' => ['auto_renew' => false, 'auto_reissue' => true],
    ]);

    $product = Product::factory()->create([
        'status' => 1, 'renew' => 1, 'reissue' => 1, 'reuse_csr' => 0,
        'product_type' => Product::TYPE_SSL, 'validation_type' => 'dv',
        'validation_methods' => ['delegation', 'txt'],
        'common_name_types' => ['standard'], 'alternative_name_types' => ['standard'],
        'periods' => [12], 'standard_min' => 0, 'standard_max' => 5,
        'wildcard_min' => 0, 'wildcard_max' => 0, 'total_min' => 1, 'total_max' => 5,
        'refund_period' => 30,
    ]);
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '50.00', 'alternative_wildcard_price' => '0.00',
    ]);

    $domains = 'a-'.uniqid().'.example.com,b-'.uniqid().'.example.com';
    $order = Order::factory()->create([
        'user_id' => $user->id, 'product_id' => $product->id,
        'auto_reissue' => true, 'period' => 12,
        'period_from' => now()->subMonths(3), 'period_till' => now()->addMonths(6), // >15 天 → 重签
        'amount' => '100.00', 'purchased_standard_count' => 1,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'common_name' => explode(',', $domains)[0], 'alternative_names' => $domains,
        'standard_count' => 2, 'wildcard_count' => 0,
        'channel' => 'web', 'expires_at' => now()->addDays(3),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $intents = [];
    captureAutoRenewIntents($intents);
    depleteBalanceOnFirstCert($user);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    // 旧证书回滚保持 active（未 reissued），reissue cert 未残留（latest_cert_id 仍指旧证书）
    expect($cert->fresh()->status)->toBe('active')
        ->and($order->fresh()->latest_cert_id)->toBe($cert->id)
        ->and(Cert::where('order_id', $order->id)->count())->toBe(1)
        ->and($user->fresh()->balance)->toBe('1000.00');
});

test('O1 K1：事务内零上游 —— 成功续费全程不触达 Order\\Api\\Api（commit 由事务外延时任务承载）', function () {
    Http::preventStrayRequests();

    // Api 任一方法被调用即失败（证明 new/pay/事务内无上游；commit 已入队未执行）
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('new')->never();
    $api->shouldReceive('renew')->never();
    $api->shouldReceive('reissue')->never();
    $api->shouldReceive('commit')->never();
    $api->shouldReceive('cancel')->never();
    $this->app->instance(Api::class, $api);

    $user = User::factory()->withBalance('1000.00')->withAutoRenew()->create();
    [$order, $cert] = makeRenewableOrder($user);

    $intents = [];
    captureAutoRenewIntents($intents);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    // 续费成功落库、上游从未被调用
    expect($cert->fresh()->status)->toBe('renewed');
    Queue::assertPushed(TaskJob::class);
});

test('O2：同用户两续费单、余额仅够一单 → 第二单预检拦截（refresh 读到前序扣费）', function () {
    // 不加 refresh 则第二单读 00:00 预载的 stale 余额误放行到 charge；加 refresh 后读到前序扣费真实余额被拦。
    $user = User::factory()->withBalance('100.00')->withAutoRenew()->create();
    [$order1, $cert1] = makeRenewableOrder($user);
    [$order2, $cert2] = makeRenewableOrder($user);

    $intents = [];
    captureAutoRenewIntents($intents);

    $this->artisan('schedule:auto-renew')->assertSuccessful();

    // 顺序无关断言（不依赖 getRenewOrders 无 orderBy 时的隐式主键序）：恰一单成功续费、一单被预检拦截。
    $freshCerts = collect([$cert1->fresh(), $cert2->fresh()]);
    $renewedCerts = $freshCerts->where('status', 'renewed');
    $activeCerts = $freshCerts->where('status', 'active');

    expect($renewedCerts)->toHaveCount(1)             // 恰一单成功续费（renewed）
        ->and($activeCerts)->toHaveCount(1)           // 恰一单被拦（旧证书保持 active）
        ->and($user->fresh()->balance)->toBe('0.00'); // 成功单扣光余额

    // 被拦单无新证书（仅旧证书一条）——按保持 active 的那单定位，顺序无关
    $blockedOrderId = $activeCerts->first()->order_id;
    expect(Cert::where('order_id', $blockedOrderId)->count())->toBe(1);

    // 发「余额不足」通知（区别于 charge 回滚的兜底文案，证明拦在预检而非 charge）
    $reasons = collect($intents)->where('code', 'auto_renew_failed')->pluck('context.reason')->all();
    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('余额不足');
});
