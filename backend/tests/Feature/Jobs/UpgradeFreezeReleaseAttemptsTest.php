<?php

declare(strict_types=1);

use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Jobs\ProbeTriesFiveJob;
use Tests\Fixtures\Jobs\ProbeTriesOneJob;

// TestCase + RefreshDatabase 由 tests/Pest.php 对 Feature/Jobs 统一注入，勿在此重复 uses()。

/*
 * 复现「升级冻结 release 误杀 tries=1 Job」并验证 tries=5 + maxExceptions=1 修复。
 *
 * 关键：必须用 database 队列驱动（sync 永远 attempts=1，无法复现）。手动构造一个
 * Worker 单步驱动 pop，配合时间旅行跳过 release 的 60s 延迟，忠实重放 worker 行为。
 * 用 JobFailed 事件（Job::fail() 必派发）捕获失败异常，而非 failed_jobs 表——后者由
 * WorkCommand 注册的监听器写入，手动构造的 worker 不接该 writer。
 */

beforeEach(function () {
    UpgradeFreezeLock::unfreeze();
    Cache::flush();
    ProbeTriesOneJob::$ran = 0;
    ProbeTriesFiveJob::$ran = 0;
    ProbeTriesFiveJob::$throw = false;

    // 强制 database 驱动（phpunit 默认可能是 sync，sync 不记 attempts）
    config(['queue.default' => 'database']);
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze();
});

/**
 * 构造一个可手动单步 pop 的 worker：
 *  - 注入 cache 以支持 maxExceptions 计数
 *  - 用静默 ExceptionHandler 避免把 MaxAttempts/业务异常打到日志
 */
function probeWorker(): Worker
{
    $silent = new class implements ExceptionHandler
    {
        public function report(Throwable $e) {}

        public function shouldReport(Throwable $e)
        {
            return false;
        }

        public function render($request, Throwable $e)
        {
            return null;
        }

        public function renderForConsole($output, Throwable $e) {}
    };

    $worker = new Worker(
        app('queue'),
        app('events'),
        $silent,
        fn () => false,
    );
    $worker->setCache(Cache::store());

    return $worker;
}

/**
 * 跑一次队列（单次 pop + process），job 的 $tries 覆盖 WorkerOptions。
 */
function popOnce(Worker $worker): void
{
    $worker->runNextJob('database', 'default', new WorkerOptions);
}

/**
 * 监听 JobFailed，返回收集异常的引用数组。
 *
 * @param  array<int, Throwable>  $bucket
 */
function captureJobFailures(array &$bucket): void
{
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$bucket) {
        $bucket[] = $event->exception;
    });
}

test('tries=1 Job 被 freeze release 一次后第二次 pop 即 MaxAttemptsExceeded，handle 永不执行（Laravel 13 attempts 语义）', function () {
    $failures = [];
    captureJobFailures($failures);

    UpgradeFreezeLock::freeze();
    ProbeTriesOneJob::dispatch();

    $worker = probeWorker();

    // pop #1：冻结中 → 中间件 release(60)，handle 不跑、未失败、仍在队列
    popOnce($worker);
    expect(ProbeTriesOneJob::$ran)->toBe(0)
        ->and($failures)->toBeEmpty()
        ->and(Queue::connection('database')->size('default'))->toBe(1);

    // 解冻——若不是 attempts 被吃掉，第二次 pop 本应正常执行业务
    UpgradeFreezeLock::unfreeze();
    $this->travel(61)->seconds(); // 跳过 release 的 60s 延迟，让任务重新可见

    // pop #2：attempts=2 > tries=1 → markJobAsFailedIfAlreadyExceedsMaxAttempts 在 fire() 前抛
    popOnce($worker);

    // handle 始终没跑——纯粹被 release 消耗掉的那次 attempt 杀死
    expect(ProbeTriesOneJob::$ran)->toBe(0)
        ->and(Queue::connection('database')->size('default'))->toBe(0) // 已不在队列
        ->and($failures)->toHaveCount(1)
        ->and($failures[0])->toBeInstanceOf(MaxAttemptsExceededException::class);
});

test('tries=5 + maxExceptions=1 Job 经多次 freeze release 仍存活，解冻后 handle 正常执行一次', function () {
    $failures = [];
    captureJobFailures($failures);

    UpgradeFreezeLock::freeze();
    ProbeTriesFiveJob::dispatch();

    $worker = probeWorker();

    // 连续 3 次 freeze release（attempts 累到 3，仍 <= 5）
    foreach (range(1, 3) as $ignored) {
        popOnce($worker);
        expect(ProbeTriesFiveJob::$ran)->toBe(0); // 冻结期 handle 不跑
        $this->travel(61)->seconds();             // 跳过 release 延迟
    }
    expect($failures)->toBeEmpty(); // 没有被误杀

    // 解冻 → 下一次 pop 执行业务
    UpgradeFreezeLock::unfreeze();
    popOnce($worker);

    expect(ProbeTriesFiveJob::$ran)->toBe(1)
        ->and($failures)->toBeEmpty()
        ->and(Queue::connection('database')->size('default'))->toBe(0); // 成功删除
});

test('tries=5 + maxExceptions=1：handle 真正抛业务异常时第一次即失败且不重试（maxExceptions 封顶）', function () {
    $failures = [];
    captureJobFailures($failures);

    ProbeTriesFiveJob::$throw = true; // handle 一跑就抛
    ProbeTriesFiveJob::dispatch();    // 不冻结，直接进 handle

    $worker = probeWorker();

    // pop #1：handle 执行并抛 → handleJobException → maxExceptions=1 命中 → 立即 failJob
    popOnce($worker);

    expect(ProbeTriesFiveJob::$ran)->toBe(1)                            // 业务确实跑了一次
        ->and(Queue::connection('database')->size('default'))->toBe(0)  // 未被重新入队重试
        ->and($failures)->toHaveCount(1)
        ->and($failures[0]->getMessage())->toBe('probe-business-failure') // 失败的是业务异常
        ->and($failures[0])->not->toBeInstanceOf(MaxAttemptsExceededException::class);

    // 再 pop 一次：队列空，handle 不会再跑（确认未重试）
    $this->travel(61)->seconds();
    popOnce($worker);
    expect(ProbeTriesFiveJob::$ran)->toBe(1);
});

test('受影响的备份类 Job 已配置 tries=5 + maxExceptions=1（freeze 不误杀；maxExceptions 封顶 worker 级重试）', function () {
    foreach ([CreateBackupJob::class, RestoreBackupJob::class] as $class) {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        expect($defaults['tries'] ?? null)->toBe(5, "$class 的 tries 应为 5（吸收 freeze release）")
            ->and($defaults['maxExceptions'] ?? null)->toBe(1, "$class 的 maxExceptions 应为 1（worker 级异常封顶一次）");
    }
});
