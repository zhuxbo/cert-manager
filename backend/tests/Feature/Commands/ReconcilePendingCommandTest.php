<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    // T5：admin 到顶告警改由 SystemAlert 每日快照承载（日期指纹去重走 Cache）；跨 test flush 防指纹污染。
    Cache::flush();
    config([
        'reconcile.pending_stale_minutes' => 10,
        'reconcile.max_reconcile_ids' => 20,
        'reconcile.max_attempts' => 3,
        'reconcile.retry_delay_minutes' => 10,
    ]);
});

/**
 * 造一条「产品缺失」失败 commit task（result msg 命中 gateway 'Product not found' 信号）。
 * count=1 即被主扫描 NOT EXISTS product-missing 排除，无需到 max_attempts。
 */
function makeProductMissingFailedTask(int $orderId): Task
{
    return Task::factory()->failed()->create([
        'order_id' => $orderId,
        'action' => 'commit',
        'attempts' => 1,
        'last_execute_at' => now()->subMinutes(5),
        'result' => ['code' => 0, 'msg' => 'Product not found'],
    ]);
}

function makeReconcileOrder(array $certAttrs = []): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create(array_merge([
        'order_id' => $order->id,
        'status' => 'pending',
        'api_id' => null,
        'created_at' => now()->subMinutes(20),
    ], $certAttrs));

    $order->update(['latest_cert_id' => $cert->id]);

    return $order;
}

function hasReconcileCommitTask(int $orderId): bool
{
    return Task::where('order_id', $orderId)
        ->where('action', 'commit')
        ->where('status', 'executing')
        ->exists();
}

/**
 * 在「当前对账周期」内（cert.created_at=subMinutes(20) 之后）造 $count 条失败 commit task，
 * 返回最后创建（last_execute_at 最新、id 最大 → 转人工扫描 alertMaxedOrders 选中）的一条。
 */
function makeInCycleFailedTasks(int $orderId, int $count = 3): Task
{
    $latest = null;
    for ($i = $count; $i >= 1; $i--) {
        $latest = Task::factory()->failed()->create([
            'order_id' => $orderId,
            'action' => 'commit',
            'attempts' => 1,
            'last_execute_at' => now()->subMinutes($i * 3), // 3*count..3 分钟前，均晚于 cert.created_at
        ]);
    }

    return $latest;
}

test('签名为 schedule:reconcile-pending', function () {
    $this->artisan('schedule:reconcile-pending')->assertSuccessful();
});

test('stale pending 且 api_id 为空的订单会创建 commit 任务', function () {
    $order = makeReconcileOrder();

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeTrue();
});

test('未超过 stale 阈值的 pending 订单不处理', function () {
    $order = makeReconcileOrder(['created_at' => now()->subMinutes(2)]);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

test('已有 api_id 或非 pending 的订单不处理', function () {
    $withApiId = makeReconcileOrder(['api_id' => 'upstream-id']);
    $processing = makeReconcileOrder(['status' => 'processing', 'api_id' => 'upstream-processing']);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($withApiId->id))->toBeFalse();
    expect(hasReconcileCommitTask($processing->id))->toBeFalse();
});

test('重复执行不会重复创建 commit 任务', function () {
    $order = makeReconcileOrder();

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();
    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(Task::where('order_id', $order->id)->where('action', 'commit')->count())->toBe(1);
});

test('失败对账周期数达到上限后不再创建任务', function () {
    $order = makeReconcileOrder();
    // 3 个失败的 commit task 行 = 3 个失败的对账周期（每周期建一个 task）→ 达 max_attempts=3 → 转人工、不再建新 task
    // C4：last_execute_at 必须晚于 cert.created_at（subMinutes(20)）才计入本周期，否则被窗口排除
    foreach ([15, 10, 5] as $agoMinutes) {
        Task::factory()->failed()->create([
            'order_id' => $order->id,
            'action' => 'commit',
            'attempts' => 1,
            'last_execute_at' => now()->subMinutes($agoMinutes),
        ]);
    }

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

test('单个失败 task 的 worker attempts 高不提前触顶（按对账周期行数、非 sum worker attempts）', function () {
    $order = makeReconcileOrder();
    // 仅 1 个失败对账周期，但该 task 被 worker 重试到 attempts=5。
    // 旧逻辑 sum('attempts')=5>=3 会误判到顶、转人工不再重发；新逻辑 count()=1<3 且退避已过 → 仍应重发
    // C4：last_execute_at=subMinutes(15) 晚于 cert.created_at（subMinutes(20)）计入本周期，且退避（10min）已过
    Task::factory()->failed()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'attempts' => 5,
        'last_execute_at' => now()->subMinutes(15),
    ]);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeTrue();
});

test('到顶单触发 admin 每日快照告警且同日不重复告警', function () {
    // T5：admin 到顶告警载体由「per-order task_failed 永久键」迁「每日快照 SystemAlert」（M5 吸收——
    // 日期指纹每日重发，消除 SMTP 瞬断永久丢告警）。SystemAlert 内部经 NotificationCenter dispatch
    // system_alert intent。「告警可达 + 去重」不变式等价保留，另获每日重发增强。
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'web']); // 非 auto 单只发 admin，隔离用户 dispatch 计数
    // 3 个失败对账周期；C4：last_execute_at 晚于 cert.created_at（subMinutes(20)）计入本周期
    foreach ([15, 10, 5] as $agoMinutes) {
        Task::factory()->failed()->create([
            'order_id' => $order->id,
            'action' => 'commit',
            'attempts' => 1,
            'last_execute_at' => now()->subMinutes($agoMinutes),
        ]);
    }

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once()->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();
    $this->artisan('schedule:reconcile-pending')->assertSuccessful(); // 同日日期指纹去重 → 不再 dispatch（once 兜住）

    expect($intents)->toHaveCount(1);
    $intent = $intents[0];
    expect($intent->code)->toBe('system_alert')
        ->and($intent->notifiableType)->toBe('admin')
        ->and($intent->context['category'])->toBe('reconcile_maxed')
        ->and($intent->context['details']['total'])->toBe(1)
        ->and($intent->context['details']['order_sample'])->toContain((string) $order->id)
        ->and(hasReconcileCommitTask($order->id))->toBeFalse(); // 到顶单不建新 task
});

test('失败后仍在退避窗口内时不创建任务', function () {
    $order = makeReconcileOrder();
    Task::factory()->failed()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'attempts' => 1,
        'last_execute_at' => now()->subMinutes(5),
    ]);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

// ==================== C2：到顶同步通知用户（auto_renew_failed） ====================

test('C2：channel=auto 到顶时发 admin 每日快照 + user auto_renew_failed', function () {
    // admin 通道换形态（task_failed → system_alert 每日快照）；user 可达性/内容字段是 C2 红线，原样保留。
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'auto']);
    $user = $order->user;
    $cert = $order->latestCert;
    $latestFailed = makeInCycleFailedTasks($order->id, 3);

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->twice()->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    $byCode = collect($intents)->keyBy('code');
    expect($intents)->toHaveCount(2)
        ->and($byCode->has('system_alert'))->toBeTrue()   // admin 每日快照
        ->and($byCode->has('auto_renew_failed'))->toBeTrue(); // user 逐单

    $adminIntent = $byCode['system_alert'];
    expect($adminIntent->notifiableType)->toBe('admin')
        ->and($adminIntent->context['category'])->toBe('reconcile_maxed');

    $userIntent = $byCode['auto_renew_failed'];
    expect($userIntent->notifiableType)->toBe('user')
        ->and($userIntent->notifiableId)->toBe($user->id)
        ->and($userIntent->context['common_name'])->toBe($cert->common_name)
        ->and($userIntent->context['action'])->toBe($cert->action)
        ->and($userIntent->context['email'])->toBe($user->email);

    // user 键落库；admin 载体迁 Cache 日期指纹后不再落 task.result
    $result = $latestFailed->fresh()->result;
    expect($result['reconcile_user_alerted_at'] ?? null)->not->toBeNull()
        ->and($result['reconcile_alerted_at'] ?? null)->toBeNull();
});

test('C2：channel≠auto（web）到顶时只发 admin 快照、不发用户', function () {
    // channel gate 语义（手动单不发「自动X失败」）不变；仅 admin 通道换 system_alert 快照形态。
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'web']);
    $latestFailed = makeInCycleFailedTasks($order->id, 3);

    $codes = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once()->with(Mockery::on(function (NotificationIntent $intent) use (&$codes) {
        $codes[] = $intent->code;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect($codes)->toBe(['system_alert']); // 仅 admin 快照，不发 user
    $result = $latestFailed->fresh()->result;
    expect($result['reconcile_user_alerted_at'] ?? null)->toBeNull();
});

test('C2：admin 日期指纹 + user 键各自去重，重复执行不重发', function () {
    // user 去重载体不变（reconcile_user_alerted_at 键）；admin 载体迁 Cache 日期指纹，同日去重语义等价验证。
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'auto']);
    $latestFailed = makeInCycleFailedTasks($order->id, 3);

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->twice(); // 首轮 admin 快照 + user 各一次；次轮 admin 日期指纹 + user 键去重 → 0 次
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();
    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    $result = $latestFailed->fresh()->result;
    expect($result['reconcile_user_alerted_at'] ?? null)->not->toBeNull();
});

test('C2：user 键缺失的到顶单本轮补发 user（不被历史 admin 键阻塞）', function () {
    // C2 原意=admin 状态不得阻塞 user 补发。新架构 admin（Cache 日期指纹）/ user（task.result 键）完全解耦，
    // 该不变式天然成立：存量历史 reconcile_alerted_at 是无消费者遗留数据（admin 不读它），user 键缺失即补发。
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'auto']);
    $latestFailed = makeInCycleFailedTasks($order->id, 3);
    // 模拟 C2 上线前已 admin 告警：预置历史 admin 键（新架构不读它），缺 user 键
    $seed = is_array($latestFailed->result) ? $latestFailed->result : [];
    $seed['reconcile_alerted_at'] = now()->subMinutes(1)->toDateTimeString();
    $latestFailed->forceFill(['result' => $seed])->save();

    $codes = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->with(Mockery::on(function (NotificationIntent $intent) use (&$codes) {
        $codes[] = $intent->code;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    // 锚定 user 补发（不钉总次数：admin/user 解耦后 admin 快照也发一封）
    expect($codes)->toContain('auto_renew_failed');
    expect($latestFailed->fresh()->result['reconcile_user_alerted_at'] ?? null)->not->toBeNull();
});

// ==================== C3：executing 过滤下沉进 SQL，不占 limit 名额 ====================

test('C3：有 executing commit task 的 pending 单不占 limit 名额', function () {
    config(['reconcile.max_reconcile_ids' => 1]); // limit=1，凸显名额占用

    // 订单 A（id 更小）已有 executing commit task；订单 B（id 更大）无 task
    $orderA = makeReconcileOrder();
    Task::factory()->create([
        'order_id' => $orderA->id,
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now(),
    ]);
    $orderB = makeReconcileOrder();

    expect($orderA->id)->toBeLessThan($orderB->id); // orderBy('id') 下 A 排前

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    // 修复前：A 占满 limit(1)，B 被挤出 → B 无 task；修复后：A 被 whereNotExists 排除，B 进窗口 → B 建 task
    expect(hasReconcileCommitTask($orderB->id))->toBeTrue();
});

test('C3/T5：到顶单被主扫描排除但转人工扫描仍触发告警（前移不杀告警护栏）', function () {
    // 护栏：前移到顶排除不得杀告警（07-07 §6）。新形态下同一不变式由转人工扫描承载——到顶单被主扫描
    // 排除（不建 task、不占 limit，让新卡单进窗），但转人工扫描仍发 admin 快照。等价改写非删除。
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'web']); // 非 auto，仅 admin 告警
    makeInCycleFailedTasks($order->id, 3); // 3 条 failed、无 executing

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once()->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    // 到顶单不建新 task（前移排除），admin 快照告警仍发（转人工扫描承载可达性）
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->code)->toBe('system_alert');
    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

test('C3：whereNotExists 与 hasExecutingCommitTask 语义等价（非 executing-commit task 不排除订单）', function () {
    // executing 的 sync task（action≠commit）不应排除订单
    $orderSync = makeReconcileOrder();
    Task::factory()->create([
        'order_id' => $orderSync->id,
        'action' => 'sync',
        'status' => 'executing',
        'started_at' => now(),
    ]);
    // stopped 的 commit task（status≠executing）不应排除订单
    $orderStopped = makeReconcileOrder();
    Task::factory()->create([
        'order_id' => $orderStopped->id,
        'action' => 'commit',
        'status' => 'stopped',
        'started_at' => now(),
    ]);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($orderSync->id))->toBeTrue()
        ->and(hasReconcileCommitTask($orderStopped->id))->toBeTrue();
});

// ==================== C4：失败计数锚定当前证书 created_at（防旧账误判到顶） ====================

test('C4：旧周期失败 task 不计入当前周期（不误判到顶）', function () {
    $order = makeReconcileOrder(); // cert.created_at = subMinutes(20)
    // 旧周期 3 条 failed commit task：last_execute_at 均早于当前证书 created_at
    foreach ([3, 2, 1] as $daysAgo) {
        Task::factory()->failed()->create([
            'order_id' => $order->id,
            'action' => 'commit',
            'attempts' => 1,
            'last_execute_at' => now()->subDays($daysAgo),
        ]);
    }

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    // 修复前全历史 count=3 会误判到顶不重试；修复后本周期 count=0 < max → 仍建 commit task
    expect(hasReconcileCommitTask($order->id))->toBeTrue();
});

test('C4：本周期 3 条失败仍正确到顶（不破坏真到顶）', function () {
    $order = makeReconcileOrder(); // cert.created_at = subMinutes(20)
    makeInCycleFailedTasks($order->id, 3); // 3 条本周期 failed → count=3 到顶

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

// ==================== T8：产品缺失（Product not found）永久拒单识别 ====================

test('T8：产品缺失单（result msg=Product not found）被主扫描排除、不建新 task', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'web']);
    makeProductMissingFailedTask($order->id); // count=1 < max=3，但含 product-missing 信号 → 仍被排除

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

test('T8：产品缺失单不占 limit 名额，新卡单进窗', function () {
    config(['reconcile.max_reconcile_ids' => 1]); // limit=1，凸显名额占用
    Admin::factory()->create(['email' => 'ops@example.test']);
    $orderMissing = makeReconcileOrder(); // id 小，orderBy 排前
    makeProductMissingFailedTask($orderMissing->id);
    $orderFresh = makeReconcileOrder(); // id 大，无 task
    expect($orderMissing->id)->toBeLessThan($orderFresh->id);

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->zeroOrMoreTimes();
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    // 产品缺失单被 actionable 排除、让出 limit(1) → 新卡单进窗建 task（不再队头阻塞）
    expect(hasReconcileCommitTask($orderFresh->id))->toBeTrue()
        ->and(hasReconcileCommitTask($orderMissing->id))->toBeFalse();
});

test('T8：产品缺失 auto 单转人工发 user（产品已下线文案）+ 进当日快照', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'auto']);
    makeProductMissingFailedTask($order->id);

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->twice()->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    $byCode = collect($intents)->keyBy('code');
    expect($byCode->has('system_alert'))->toBeTrue()
        ->and($byCode->has('auto_renew_failed'))->toBeTrue();
    // admin 快照 details 归类 product_missing
    expect($byCode['system_alert']->context['details']['product_missing_total'])->toBe(1)
        ->and($byCode['system_alert']->context['details']['maxed_total'])->toBe(0);
    // user 专属文案（产品缺失是可行动项，非承诺式措辞）
    expect($byCode['auto_renew_failed']->context['reason'])->toBe('所选产品已下线，请重新选购后提交');
    // 产品缺失单不建新 commit task（被主扫描排除）
    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

test('T8：产品缺失单重复运行 user 不重发（键去重）', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'auto']);
    makeProductMissingFailedTask($order->id);

    $userCount = 0;
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->with(Mockery::on(function (NotificationIntent $intent) use (&$userCount) {
        if ($intent->code === 'auto_renew_failed') {
            $userCount++;
        }

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();
    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect($userCount)->toBe(1); // reconcile_user_alerted_at 键去重，每卡单周期一封
});

test('T8：failed task msg 不含信号串时不被误排除（正常退避/重试路径）', function () {
    $order = makeReconcileOrder();
    // 1 条普通 failed（msg 非 product-missing），退避（10min）已过 → 应建 task，不被 product-missing 误排除
    Task::factory()->failed()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'attempts' => 1,
        'last_execute_at' => now()->subMinutes(15),
        'result' => ['code' => 0, 'msg' => '上游超时'],
    ]);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeTrue();
});

test('T5/T8：到顶单与产品缺失单同日合并进同一封快照', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $maxedOrder = makeReconcileOrder(['channel' => 'web']);
    makeInCycleFailedTasks($maxedOrder->id, 3); // 到顶
    $missingOrder = makeReconcileOrder(['channel' => 'web']);
    makeProductMissingFailedTask($missingOrder->id); // 产品缺失

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once()->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect($intents)->toHaveCount(1); // 一封快照合并两类（web，无 user）
    $details = $intents[0]->context['details'];
    expect($details['total'])->toBe(2)
        ->and($details['maxed_total'])->toBe(1)
        ->and($details['product_missing_total'])->toBe(1);
});

test('T5：跨日快照重发（日期指纹变化）', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'web']);
    makeInCycleFailedTasks($order->id, 3);

    $snapshotCount = 0;
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->with(Mockery::on(function (NotificationIntent $intent) use (&$snapshotCount) {
        if ($intent->code === 'system_alert') {
            $snapshotCount++;
        }

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful(); // day 1 一封
    Carbon::setTestNow(now()->addDay());
    $this->artisan('schedule:reconcile-pending')->assertSuccessful(); // day 2 指纹变化 → 再发
    Carbon::setTestNow();

    expect($snapshotCount)->toBe(2);
});
