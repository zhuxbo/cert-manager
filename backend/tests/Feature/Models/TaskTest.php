<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

test('任务关联订单', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $task = Task::factory()->create(['order_id' => $order->id]);

    expect($task->order_id)->toBe($order->id);
});

test('任务默认状态为 executing', function () {
    $task = Task::factory()->create();

    expect($task->status)->toBe('executing');
});

test('任务处理中状态', function () {
    $task = Task::factory()->processing()->create();

    expect($task->status)->toBe('executing');
    expect($task->started_at)->not->toBeNull();
    expect($task->attempts)->toBe(1);
});

test('任务已完成状态', function () {
    $task = Task::factory()->completed()->create();

    expect($task->status)->toBe('successful');
    expect($task->result)->toBeArray();
    expect($task->result['success'])->toBeTrue();
    expect($task->last_execute_at)->not->toBeNull();
});

test('任务已失败状态', function () {
    $task = Task::factory()->failed()->create();

    expect($task->status)->toBe('failed');
    expect($task->result['error'])->toBe('Task execution failed');
    expect($task->attempts)->toBe(3);
});

test('result 字段为 JSON cast', function () {
    $task = Task::factory()->create([
        'result' => ['key' => 'value', 'nested' => ['a' => 1]],
    ]);
    $task->refresh();

    expect($task->result)->toBeArray();
    expect($task->result['key'])->toBe('value');
    expect($task->result['nested']['a'])->toBe(1);
});

test('日期字段正确转换', function () {
    $task = Task::factory()->completed()->create();
    $task->refresh();

    expect($task->started_at)->toBeInstanceOf(Carbon::class);
    expect($task->last_execute_at)->toBeInstanceOf(Carbon::class);
});

test('weight 为整数 cast', function () {
    $task = Task::factory()->create(['weight' => '10']);
    $task->refresh();

    expect($task->weight)->toBeInt();
});

test('weight 默认值为 0 时创建后自动更新为 id', function () {
    $task = Task::factory()->create(['weight' => 0]);
    $task->refresh();

    expect($task->weight)->toBe($task->id);
});

test('weight 非零时不自动更新', function () {
    $task = Task::factory()->create(['weight' => 999]);
    $task->refresh();

    expect($task->weight)->toBe(999);
});

test('任务支持不同 action 类型', function () {
    $taskNew = Task::factory()->create(['action' => 'new']);
    expect($taskNew->action)->toBe('new');

    $taskReissue = Task::factory()->reissue()->create();
    expect($taskReissue->action)->toBe('reissue');

    $taskRenew = Task::factory()->renew()->create();
    expect($taskRenew->action)->toBe('renew');

    $taskRevoke = Task::factory()->revoke()->create();
    expect($taskRevoke->action)->toBe('revoke');
});

test('任务 source 字段', function () {
    $task = Task::factory()->create(['source' => 'system']);
    expect($task->source)->toBe('system');

    $task2 = Task::factory()->create(['source' => 'user']);
    expect($task2->source)->toBe('user');
});
