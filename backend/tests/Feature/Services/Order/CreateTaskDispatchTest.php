<?php

use App\Models\Order;
use App\Models\Task;
use App\Services\Order\Action;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;

test('T4：createTask dispatch 失败时 Log::error 留痕、task 行保留、不抛（最小兜底）', function () {
    // mock Bus Dispatcher 使无事务上下文的同步 dispatch 抛（模拟 jobs 表损坏/磁盘满等罕见 push 失败）。
    // 事务内 push 失败由 afterCommit 推迟到 commit 后回调、同步 try/catch 捕不到（权威兜底=T1 sweeper）；
    // 本 catch 仅覆盖无事务上下文的同步 dispatch 失败。
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('dispatch boom'));
    $dispatcher->shouldReceive('dispatchToQueue')->andThrow(new RuntimeException('dispatch boom'));
    $dispatcher->shouldIgnoreMissing();
    $this->app->instance(Dispatcher::class, $dispatcher);

    Log::spy();

    $order = Order::factory()->create();

    // 无事务上下文直调：afterCommit 无活跃事务时立即 dispatch → 抛 → 被 T4 catch 兜住（不抛出）
    (new Action)->createTask((int) $order->id, 'commit');

    // task 行保留（catch 不删、不改状态、不 rethrow）
    expect(Task::where('order_id', $order->id)->where('action', 'commit')->where('status', 'executing')->count())->toBe(1);
    // Log::error 留痕
    Log::shouldHaveReceived('error')->withArgs(fn ($msg) => $msg === 'createTask dispatch 失败')->once();
});
