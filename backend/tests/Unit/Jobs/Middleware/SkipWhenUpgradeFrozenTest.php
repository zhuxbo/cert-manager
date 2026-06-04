<?php

use App\Jobs\Middleware\SkipWhenUpgradeFrozen;
use App\Utils\UpgradeFreezeLock;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    UpgradeFreezeLock::unfreeze();
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze();
});

/**
 * 一个简化的 Job 替身，不依赖 InteractsWithQueue。
 * 仅用来测中间件是否调用 release / 是否调用 next。
 */
function makeFakeJob(): object
{
    return new class
    {
        public int $releaseCalls = 0;

        public ?int $releasedDelay = null;

        public function release(int $delay = 0): void
        {
            $this->releaseCalls++;
            $this->releasedDelay = $delay;
        }
    };
}

test('非 freeze 状态下 next 被调用，业务执行', function () {
    $middleware = new SkipWhenUpgradeFrozen;
    $job = makeFakeJob();
    $nextCalls = 0;

    $next = function ($passedJob) use (&$nextCalls, $job) {
        $nextCalls++;
        expect($passedJob)->toBe($job);
    };

    $middleware->handle($job, $next);

    expect($nextCalls)->toBe(1)
        ->and($job->releaseCalls)->toBe(0);
});

test('freeze 状态下不调 next，调 release(60)', function () {
    UpgradeFreezeLock::freeze();

    $middleware = new SkipWhenUpgradeFrozen;
    $job = makeFakeJob();
    $nextCalls = 0;

    $next = function () use (&$nextCalls) {
        $nextCalls++;
    };

    $middleware->handle($job, $next);

    expect($nextCalls)->toBe(0)
        ->and($job->releaseCalls)->toBe(1)
        ->and($job->releasedDelay)->toBe(SkipWhenUpgradeFrozen::RELEASE_DELAY_SECONDS)
        ->and(SkipWhenUpgradeFrozen::RELEASE_DELAY_SECONDS)->toBe(60);
});

test('freeze 期间 release 后 unfreeze，再次进入中间件业务执行', function () {
    // 模拟：worker 先在 freeze 中拉到 Job，被 release 60s 后 unfreeze，
    // 60s 后 worker 重拉时 freeze 已解除，本次不再 release，转而执行业务。
    UpgradeFreezeLock::freeze();

    $middleware = new SkipWhenUpgradeFrozen;
    $job = makeFakeJob();
    $nextCalls = 0;

    $next = function () use (&$nextCalls) {
        $nextCalls++;
    };

    // 第一次：freeze 中
    $middleware->handle($job, $next);
    expect($nextCalls)->toBe(0)
        ->and($job->releaseCalls)->toBe(1);

    // 解锁
    UpgradeFreezeLock::unfreeze();

    // 第二次：worker 重新拉取（实际通过 release 60s 后入队，这里直接调）
    $middleware->handle($job, $next);

    expect($nextCalls)->toBe(1)
        ->and($job->releaseCalls)->toBe(1); // 第二次未再 release
});
