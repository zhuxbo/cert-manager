<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config([
        'reconcile.pending_stale_minutes' => 10,
        'reconcile.max_reconcile_ids' => 20,
        'reconcile.max_attempts' => 3,
        'reconcile.retry_delay_minutes' => 10,
    ]);
});

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
 * 返回最后创建（last_execute_at 最新、id 最大 → alertMaxAttempts 选中）的一条。
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

test('失败对账周期数达到上限后发起人工告警且不会重复告警', function () {
    $admin = Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'web']); // C2：非 auto 单只发 admin，隔离用户 dispatch 计数
    // 3 个失败对账周期；告警选中 last_execute_at 最新（+id 最大）的那条 = 最后创建的
    // C4：last_execute_at 晚于 cert.created_at（subMinutes(20)）计入本周期
    $latestFailed = null;
    foreach ([15, 10, 5] as $agoMinutes) {
        $latestFailed = Task::factory()->failed()->create([
            'order_id' => $order->id,
            'action' => 'commit',
            'attempts' => 1,
            'last_execute_at' => now()->subMinutes($agoMinutes),
        ]);
    }

    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function (NotificationIntent $intent) use ($admin, $latestFailed) {
            return $intent->code === 'task_failed'
                && $intent->notifiableType === 'admin'
                && $intent->notifiableId === $admin->id
                && $intent->context['task_id'] === $latestFailed->id
                && $intent->context['admin_email'] === 'ops@example.test';
        }));
    $this->app->instance(NotificationCenter::class, $notificationCenter);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();
    $alertedAt = $latestFailed->fresh()->result['reconcile_alerted_at'] ?? null;

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    $result = $latestFailed->fresh()->result;
    expect($alertedAt)->not->toBeNull()
        ->and($result['reconcile_alerted_at'])->toBe($alertedAt)
        ->and($result['reconcile_attempts'])->toBe(3)
        ->and($result['reconcile_max_attempts'])->toBe(3)
        ->and(hasReconcileCommitTask($order->id))->toBeFalse();
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

test('C2：channel=auto 到顶时同时发 admin task_failed 与 user auto_renew_failed', function () {
    $admin = Admin::factory()->create(['email' => 'ops@example.test']);
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
        ->and($byCode->has('task_failed'))->toBeTrue()
        ->and($byCode->has('auto_renew_failed'))->toBeTrue();

    $adminIntent = $byCode['task_failed'];
    expect($adminIntent->notifiableType)->toBe('admin')
        ->and($adminIntent->notifiableId)->toBe($admin->id);

    $userIntent = $byCode['auto_renew_failed'];
    expect($userIntent->notifiableType)->toBe('user')
        ->and($userIntent->notifiableId)->toBe($user->id)
        ->and($userIntent->context['common_name'])->toBe($cert->common_name)
        ->and($userIntent->context['action'])->toBe($cert->action)
        ->and($userIntent->context['email'])->toBe($user->email);

    // 双键均落库
    $result = $latestFailed->fresh()->result;
    expect($result['reconcile_alerted_at'] ?? null)->not->toBeNull()
        ->and($result['reconcile_user_alerted_at'] ?? null)->not->toBeNull();
});

test('C2：channel≠auto（web）到顶时只发 admin、不发用户', function () {
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

    expect($codes)->toBe(['task_failed']); // 手动单文案会误导，仅发 admin
    $result = $latestFailed->fresh()->result;
    expect($result['reconcile_alerted_at'] ?? null)->not->toBeNull()
        ->and($result['reconcile_user_alerted_at'] ?? null)->toBeNull();
});

test('C2：admin/user 双键各自去重，重复执行不重发', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'auto']);
    $latestFailed = makeInCycleFailedTasks($order->id, 3);

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->twice(); // 首轮 admin+user 各一次；次轮双键已在 → 不再 dispatch
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();
    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    $result = $latestFailed->fresh()->result;
    expect($result['reconcile_alerted_at'] ?? null)->not->toBeNull()
        ->and($result['reconcile_user_alerted_at'] ?? null)->not->toBeNull();
});

test('C2：存量单（已有 admin 键）本轮补发用户通知、admin 不重发', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'auto']);
    $latestFailed = makeInCycleFailedTasks($order->id, 3);
    // 模拟 C2 上线前已 admin 告警：预置 admin 键（含 attempts/max），缺 user 键
    $seed = is_array($latestFailed->result) ? $latestFailed->result : [];
    $seed['reconcile_alerted_at'] = now()->subMinutes(1)->toDateTimeString();
    $seed['reconcile_attempts'] = 3;
    $seed['reconcile_max_attempts'] = 3;
    $latestFailed->forceFill(['result' => $seed])->save();

    $codes = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once()->with(Mockery::on(function (NotificationIntent $intent) use (&$codes) {
        $codes[] = $intent->code;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect($codes)->toBe(['auto_renew_failed']); // 仅补发 user，admin 不重发（双键分判，非顶部早返）
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

test('C3：到顶订单（有 failed 无 executing）仍进 SQL 结果并触发告警（可达性护栏）', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder(['channel' => 'web']); // 非 auto，仅 admin 告警
    $latestFailed = makeInCycleFailedTasks($order->id, 3); // 3 条 failed、无 executing → 不被 whereNotExists 排除

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once(); // 到顶单仍进循环 → alertMaxAttempts
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    // 证明到顶单未被 C3 前移误杀可达性：alertMaxAttempts 落 reconcile_alerted_at，且不建新 task
    expect($latestFailed->fresh()->result['reconcile_alerted_at'] ?? null)->not->toBeNull();
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
