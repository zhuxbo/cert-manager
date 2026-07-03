<?php

use App\Exceptions\MutationBusyException;
use App\Jobs\TaskJob;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

// 方案 C 异步分流：TaskJob 调 Action::commit 抢不到 order 级互斥锁（MutationBusyException）时，
// 必须按业务互斥锁忙单独处理——未达上限长 release 等持锁上游调用结束（不标 failed），达上限才冒泡交 worker。
// MutationBusyException 独立类型不被 causedByConcurrencyError 识别，故需 TaskJob 内外层显式纳入。
uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(fn () => Cache::flush());

test('commit 抢不到 order 互斥锁未达上限：release 错峰、不标 failed、task 保持 executing', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'pending']);

    // 模拟另一请求正持有该订单互斥锁 → TaskJob 内的 commit 抢不到 → MutationBusyException
    expect(Cache::lock("order_mutate_{$order->id}", 60)->get())->toBeTrue();

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions(); // FakeJob attempts=1 < tries=3

    $job->handle(); // 不冒泡

    $job->assertReleased();   // 静默放回队列错峰重试
    $job->assertNotFailed();  // 自愈中，未 fail
    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(50)
        ->and($job->job->releaseDelay)->toBeLessThanOrEqual(70)
        ->and($task->fresh()->status)->toBe('executing'); // 未标 failed，等下次拾取
});

test('commit 抢不到 order 互斥锁达 tries 上限：冒泡交 worker failJob、不再 release', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'new', 'status' => 'pending']);

    expect(Cache::lock("order_mutate_{$order->id}", 60)->get())->toBeTrue();

    $task = Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $job = new TaskJob(['id' => $task->id]);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 3; // == tries（最后一次执行）

    $threw = false;
    try {
        $job->handle();
    } catch (MutationBusyException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();                        // 达上限冒泡（worker 据此 failJob → failed() 兜底）
    expect($job->job->isReleased())->toBeFalse();      // 不再 release
    expect($task->fresh()->status)->toBe('executing'); // 未在死事务标 failed
});
