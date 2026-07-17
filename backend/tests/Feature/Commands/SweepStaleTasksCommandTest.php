<?php

use App\Jobs\TaskJob;
use App\Models\Admin;
use App\Models\Task;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Cache::flush(); // SystemAlert 固定指纹去重走 Cache，跨 test 隔离
    config([
        'reconcile.sweeper.stale_minutes' => 30,
        'reconcile.sweeper.max_redispatch' => 3,
        'reconcile.sweeper.min_interval_minutes' => 30,
        'reconcile.sweeper.batch' => 100,
    ]);
});

function makeStaleTask(array $overrides = []): Task
{
    return Task::factory()->create(array_merge([
        'order_id' => 900001,
        'action' => 'commit',
        'status' => 'executing',
        'started_at' => now()->subMinutes(40),
        'last_execute_at' => null,
        'result' => null,
    ], $overrides));
}

test('T1：started_at 在未来的延时任务不被重派（K1）', function () {
    $task = makeStaleTask(['started_at' => now()->addHours(6)]);

    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();

    Queue::assertNotPushed(TaskJob::class);
    expect($task->fresh()->status)->toBe('executing')
        ->and($task->fresh()->result['swept_count'] ?? 0)->toBe(0);
});

test('T1：僵尸 executing 任务被重派，swept_count=1 且落 tasks 队列', function () {
    $task = makeStaleTask();

    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();

    Queue::assertPushed(TaskJob::class, fn (TaskJob $job) => $job->queue === config('queue.names.tasks'));
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('executing') // 重派不改状态，等 TaskJob 消费
        ->and($fresh->result['swept_count'])->toBe(1)
        ->and($fresh->result['last_swept_at'] ?? null)->not->toBeNull();
});

test('T1：last_execute_at 已滞后的 executing 任务也被重派', function () {
    $task = makeStaleTask(['last_execute_at' => now()->subMinutes(40)]);

    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();

    Queue::assertPushed(TaskJob::class, 1);
    expect($task->fresh()->result['swept_count'])->toBe(1);
});

test('T1：重派达上限置 stopped + swept_count 清零 + swept_exhausted_at + 转人工告警，不再 dispatch', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $task = makeStaleTask([
        'result' => ['swept_count' => 3, 'last_swept_at' => now()->subMinutes(40)->toDateTimeString()],
    ]);

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once()->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();

    Queue::assertNotPushed(TaskJob::class); // 达上限不再重派
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('stopped')
        ->and($fresh->result['swept_count'])->toBe(0) // 清零：batchStart 拉起获全新预算
        ->and($fresh->result['swept_exhausted_at'] ?? null)->not->toBeNull();
    // 转人工告警发出（category=task_stale，denylist-safe details）
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->code)->toBe('system_alert')
        ->and($intents[0]->context['category'])->toBe('task_stale')
        ->and($intents[0]->context['details']['task_id'])->toBe($task->id);
});

test('T1：观察期内（last_swept_at 未过 min_interval）不重派', function () {
    $task = makeStaleTask([
        'result' => ['swept_count' => 1, 'last_swept_at' => now()->subMinutes(10)->toDateTimeString()],
    ]);

    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();

    Queue::assertNotPushed(TaskJob::class);
    expect($task->fresh()->result['swept_count'])->toBe(1); // 未累加
});

test('T1：非 executing（stopped/successful）任务不被扫描重派（CAS/status 过滤）', function () {
    $stopped = makeStaleTask(['status' => 'stopped']);
    $successful = makeStaleTask(['status' => 'successful', 'last_execute_at' => now()->subMinutes(40)]);

    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();

    Queue::assertNotPushed(TaskJob::class);
    expect($stopped->fresh()->status)->toBe('stopped')
        ->and($successful->fresh()->status)->toBe('successful');
});

test('T1：扫描选中后锁内 CAS 复检发现 task 已被接管置终态 → 跳过不重派不计数（K2 真窗口）', function () {
    // 上一个用例测扫描层 status 过滤；本用例覆盖 sweepOne 事务内 lockForUpdate 复检分支（!$task → skipped）。
    // 单线程确定性模拟：DB::listen 在外层扫描 SELECT（唯一含 last_execute_at 谓词、非 for update）执行后、
    // sweepOne 复检前把 task 置 successful（模拟被 worker 完成）→ 锁内复检 where status='executing' 落空。
    $task = makeStaleTask();

    $flipped = false;
    DB::listen(function ($query) use ($task, &$flipped) {
        if (! $flipped
            && str_contains(strtolower($query->sql), 'last_execute_at')
            && ! str_contains(strtolower($query->sql), 'for update')) {
            $flipped = true;
            DB::table('tasks')->where('id', $task->id)->update(['status' => 'successful']);
        }
    });

    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();

    Queue::assertNotPushed(TaskJob::class); // 复检落空 → 不重派
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('successful') // 被接管的终态未被 sweeper 触碰
        ->and($fresh->result['swept_count'] ?? 0)->toBe(0); // 未累加计数
});

test('T1：exhausted 置 stopped 后不再被 sweeper 扫（无自拉起循环）', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $task = makeStaleTask([
        'result' => ['swept_count' => 3, 'last_swept_at' => now()->subMinutes(40)->toDateTimeString()],
    ]);

    // 第一轮：达上限 → stopped + swept_count=0
    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();
    expect($task->fresh()->status)->toBe('stopped');

    // 第二轮：stopped 是吸收态，sweeper 只扫 executing → 不动、不 dispatch（无自拉起循环）
    $this->artisan('schedule:sweep-stale-tasks')->assertSuccessful();
    Queue::assertNotPushed(TaskJob::class);
    expect($task->fresh()->status)->toBe('stopped')
        ->and($task->fresh()->result['swept_count'])->toBe(0);
});
