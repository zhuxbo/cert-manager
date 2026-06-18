<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// createTask 会 dispatch TaskJob；QUEUE=sync + afterCommit 在测试中会真执行 job（调 Action::sync 打上游、
// 改写 task 状态干扰断言）。fake 掉以隔离：只验证命令的「查询 + 入队 sync 任务」逻辑；
// task 保持 executing，正好供 checkRepeat 幂等断言（模拟已入队、worker 未消费的常态）
beforeEach(function () {
    Queue::fake();
});

/**
 * 造一个带 latestCert 的订单（cert 属性可覆盖 dcv/validation/status）
 */
function makeSyncOrder(array $certAttrs): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->create(array_merge(['order_id' => $order->id], $certAttrs));
    $order->update(['latest_cert_id' => $cert->id]);

    return $order;
}

/**
 * 该订单是否已有 executing 的 sync 任务
 */
function hasSyncTask(int $orderId): bool
{
    return Task::where('order_id', $orderId)
        ->where('action', 'sync')
        ->where('status', 'executing')
        ->exists();
}

test('签名为 schedule:sync', function () {
    $this->artisan('schedule:sync')->assertSuccessful();
});

test('无符合订单时正常退出', function () {
    $this->artisan('schedule:sync')->assertSuccessful();
});

test('processing 且 dcv 为空的订单会创建 sync 任务（codesign/docsign）', function () {
    // api_id 非空：真实场景 processing 必由 commit 成功设置（commit 守卫 api_id 空则报错）
    $order = makeSyncOrder(['status' => 'processing', 'dcv' => null, 'validation' => null, 'api_id' => 'upstream-cs-1']);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeTrue();
});

test('processing 且 validation 为空（dcv 非空）的订单会创建 sync 任务（smime）', function () {
    $order = makeSyncOrder(['status' => 'processing', 'dcv' => ['method' => 'email'], 'validation' => null, 'api_id' => 'upstream-sm-1']);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeTrue();
});

test('approving 且 validation 为空（dcv 非空）的订单会创建 sync 任务', function () {
    $order = makeSyncOrder(['status' => 'approving', 'dcv' => ['method' => 'email'], 'validation' => null, 'api_id' => 'upstream-sm-2']);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeTrue();
});

test('approving 且 dcv、validation 都为空的订单会创建 sync 任务', function () {
    $order = makeSyncOrder(['status' => 'approving', 'dcv' => null, 'validation' => null, 'api_id' => 'upstream-cs-3']);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeTrue();
});

test('dcv 和 validation 都非空的订单不被本命令处理（归 schedule:validate）', function () {
    $order = makeSyncOrder([
        'status' => 'processing',
        'dcv' => ['method' => 'txt'],
        'validation' => [['domain' => 'example.com', 'method' => 'txt', 'value' => 'token']],
    ]);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeFalse();
});

test('validation 为空数组 []（非 NULL）的订单不被处理（仅 NULL 命中）', function () {
    $order = makeSyncOrder(['status' => 'processing', 'dcv' => ['method' => 'txt'], 'validation' => []]);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeFalse();
});

test('pending 状态订单不被处理', function () {
    $order = makeSyncOrder(['status' => 'pending', 'dcv' => null, 'validation' => null]);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeFalse();
});

test('active 状态订单不被处理', function () {
    $order = makeSyncOrder(['status' => 'active', 'dcv' => null, 'validation' => null]);

    $this->artisan('schedule:sync')->assertSuccessful();

    expect(hasSyncTask($order->id))->toBeFalse();
});

test('重复执行不会重复创建 sync 任务（幂等）', function () {
    $order = makeSyncOrder(['status' => 'processing', 'dcv' => null, 'validation' => null, 'api_id' => 'upstream-cs-2']);

    $this->artisan('schedule:sync')->assertSuccessful();
    $this->artisan('schedule:sync')->assertSuccessful();

    expect(Task::where('order_id', $order->id)->where('action', 'sync')->count())->toBe(1);
});
