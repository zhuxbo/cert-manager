<?php

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Jobs\Middleware\SkipWhenUpgradeFrozen;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 一个仅供合并逻辑测试用的占位 middleware。
 */
final class FakeJobMiddleware
{
    public function handle(mixed $job, callable $next): mixed
    {
        return $next($job);
    }
}

test('Job 用 trait 但没有 customMiddleware 时，middleware() 仅返回 SkipWhenUpgradeFrozen', function () {
    $job = new class
    {
        use HasUpgradeFreezeMiddleware;
    };

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(SkipWhenUpgradeFrozen::class);
});

test('Job 用 trait 且定义 customMiddleware 时，middleware() 把自定义合并在 SkipWhenUpgradeFrozen 之后', function () {
    $job = new class
    {
        use HasUpgradeFreezeMiddleware;

        /**
         * @return array<int, object>
         */
        public function customMiddleware(): array
        {
            return [new FakeJobMiddleware];
        }
    };

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2);
    expect($middleware[0])->toBeInstanceOf(SkipWhenUpgradeFrozen::class);
    expect($middleware[1])->toBeInstanceOf(FakeJobMiddleware::class);
});
