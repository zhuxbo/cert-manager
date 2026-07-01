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
    foreach ([3, 2, 1] as $agoHours) {
        Task::factory()->failed()->create([
            'order_id' => $order->id,
            'action' => 'commit',
            'attempts' => 1,
            'last_execute_at' => now()->subHours($agoHours),
        ]);
    }

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeFalse();
});

test('单个失败 task 的 worker attempts 高不提前触顶（按对账周期行数、非 sum worker attempts）', function () {
    $order = makeReconcileOrder();
    // 仅 1 个失败对账周期，但该 task 被 worker 重试到 attempts=5。
    // 旧逻辑 sum('attempts')=5>=3 会误判到顶、转人工不再重发；新逻辑 count()=1<3 且退避已过 → 仍应重发
    Task::factory()->failed()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'attempts' => 5,
        'last_execute_at' => now()->subHours(2), // 退避窗口（delay 10*1=10min）已过
    ]);

    $this->artisan('schedule:reconcile-pending')->assertSuccessful();

    expect(hasReconcileCommitTask($order->id))->toBeTrue();
});

test('失败对账周期数达到上限后发起人工告警且不会重复告警', function () {
    $admin = Admin::factory()->create(['email' => 'ops@example.test']);
    $order = makeReconcileOrder();
    // 3 个失败对账周期；告警选中 last_execute_at 最新（+id 最大）的那条 = 最后创建的
    $latestFailed = null;
    foreach ([3, 2, 1] as $agoHours) {
        $latestFailed = Task::factory()->failed()->create([
            'order_id' => $order->id,
            'action' => 'commit',
            'attempts' => 1,
            'last_execute_at' => now()->subHours($agoHours),
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
