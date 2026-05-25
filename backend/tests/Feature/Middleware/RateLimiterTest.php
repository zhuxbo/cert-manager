<?php

use App\Exceptions\ApiResponseException;
use App\Http\Middleware\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

// ── 辅助函数 ──

// 用 now()->timestamp 而非 time()，与 RateLimiter::checkLimit 时钟来源对称，
// 让未来加 Carbon::setTestNow 的限流测试时，辅助函数算出的窗口键能和中间件实际键匹配。
function currentWindowKey(string $baseKey): string
{
    return "$baseKey:".(int) floor(now()->timestamp / 60);
}

function prevWindowKey(string $baseKey): string
{
    return "$baseKey:".((int) floor(now()->timestamp / 60) - 1);
}

// ── 基础行为 ──

test('RateLimiter 正常请求透传', function () {
    $middleware = new RateLimiter;
    $request = Request::create('/api/v2/products', 'GET');

    $response = $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');

    expect($response->getContent())->toBe('ok');
});

test('RateLimiter IP 限流 - 正常范围内通过', function () {
    $middleware = new RateLimiter;

    for ($i = 0; $i < 10; $i++) {
        $request = Request::create('/api/v2/products', 'GET');
        $response = $middleware->handle($request, function () {
            return new Response('ok');
        }, 'v2');

        expect($response->getContent())->toBe('ok');
    }
});

test('RateLimiter IP 限流 - 超过限制抛出异常', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:v2:127.0.0.1';

    // 当前窗口已达限额
    Cache::put(currentWindowKey($baseKey), 121, 120);

    $request = Request::create('/api/v2/products', 'GET');

    $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');
})->throws(ApiResponseException::class);

test('RateLimiter ACME 模式仅检查 IP', function () {
    $middleware = new RateLimiter;
    $request = Request::create('/acme/new-order', 'POST', [], [], [], [], '{}');

    $response = $middleware->handle($request, function () {
        return new Response('ok');
    }, 'acme');

    expect($response->getContent())->toBe('ok');
});

test('RateLimiter 默认限流更严格', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:default:127.0.0.1';

    // 默认限制为 60，低于 v1/v2 的 120
    Cache::put(currentWindowKey($baseKey), 61, 120);

    $request = Request::create('/api/other/path', 'GET');

    expect(fn () => $middleware->handle($request, function () {
        return new Response('ok');
    }, 'default'))->toThrow(ApiResponseException::class);
});

test('RateLimiter 计数器递增正确', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:v2:127.0.0.1';

    $request = Request::create('/api/v2/products', 'GET');
    $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');

    expect((int) Cache::get(currentWindowKey($baseKey)))->toBe(1);

    // 再请求一次
    $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');

    expect((int) Cache::get(currentWindowKey($baseKey)))->toBe(2);
});

test('RateLimiter deploy 模式正常通过', function () {
    $middleware = new RateLimiter;
    $request = Request::create('/api/deploy/products', 'GET');

    $response = $middleware->handle($request, function () {
        return new Response('ok');
    }, 'deploy');

    expect($response->getContent())->toBe('ok');
});

// ── 滑动窗口 ──

test('滑动窗口 - 上一窗口计数加权影响当前判定', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:v2:127.0.0.1';
    $request = Request::create('/api/v2/products', 'GET');

    // 无上一窗口数据时，首次请求正常通过
    $response = $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');
    expect($response->getContent())->toBe('ok');

    Cache::flush();

    // 上一窗口 7200 次，当前窗口 0 次
    // prevWeight 最小值 1/60（窗口末尾），估算 = 7200 × 1/60 + 1 = 121 > 120
    // 无论何时运行都必定被拒
    Cache::put(prevWindowKey($baseKey), 7200, 120);

    expect(fn () => $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2'))->toThrow(ApiResponseException::class);
});

test('滑动窗口 - 无上一窗口数据时仅看当前窗口', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:v2:127.0.0.1';

    // 上一窗口无数据，当前窗口 119 次，再请求 1 次 = 120，刚好不超限
    Cache::put(currentWindowKey($baseKey), 119, 120);

    $request = Request::create('/api/v2/products', 'GET');
    $response = $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');

    expect($response->getContent())->toBe('ok');
});

test('滑动窗口 - 当前窗口满载触发限流', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:v2:127.0.0.1';

    // 无上一窗口数据，当前窗口 120 次，再请求 = 121 > 120
    Cache::put(currentWindowKey($baseKey), 120, 120);

    $request = Request::create('/api/v2/products', 'GET');

    expect(fn () => $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2'))->toThrow(ApiResponseException::class);
});

test('滑动窗口 - 上一窗口少量请求不影响当前窗口正常使用', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:v2:127.0.0.1';

    // 上一窗口 30 次，当前窗口 80 次
    // 最大估算（窗口开始时）= 30 × 1.0 + 81 = 111 < 120，通过
    Cache::put(prevWindowKey($baseKey), 30, 120);
    Cache::put(currentWindowKey($baseKey), 80, 120);

    $request = Request::create('/api/v2/products', 'GET');
    $response = $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');

    expect($response->getContent())->toBe('ok');
});

test('滑动窗口 - 两个窗口累计超限被拒绝', function () {
    $middleware = new RateLimiter;
    $baseKey = 'rate_limit_ip:v2:127.0.0.1';

    // 上一窗口 120 次，当前窗口 119 次
    // increment 后当前 = 120，prevWeight 最小 1/60
    // 最小估算 = 120 × 1/60 + 120 = 122 > 120，无论何时运行都被拒
    Cache::put(prevWindowKey($baseKey), 120, 120);
    Cache::put(currentWindowKey($baseKey), 119, 120);

    $request = Request::create('/api/v2/products', 'GET');

    expect(fn () => $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2'))->toThrow(ApiResponseException::class);
});

test('滑动窗口 - 不同 limiter 隔离计数', function () {
    $middleware = new RateLimiter;

    // v2 计数接近限额
    $v2Key = 'rate_limit_ip:v2:127.0.0.1';
    Cache::put(currentWindowKey($v2Key), 119, 120);

    // acme 计数为 0
    $request = Request::create('/acme/new-order', 'POST', [], [], [], [], '{}');
    $response = $middleware->handle($request, function () {
        return new Response('ok');
    }, 'acme');

    expect($response->getContent())->toBe('ok');

    // acme 计数应为 1，v2 不受影响
    $acmeKey = 'rate_limit_ip:acme:127.0.0.1';
    expect((int) Cache::get(currentWindowKey($acmeKey)))->toBe(1);
    expect((int) Cache::get(currentWindowKey($v2Key)))->toBe(119);
});

test('滑动窗口 - key 包含窗口序号', function () {
    $middleware = new RateLimiter;
    $request = Request::create('/api/v2/products', 'GET');

    $middleware->handle($request, function () {
        return new Response('ok');
    }, 'v2');

    $windowNumber = (int) floor(now()->timestamp / 60);
    $expectedKey = "rate_limit_ip:v2:127.0.0.1:$windowNumber";

    expect(Cache::has($expectedKey))->toBeTrue();
    expect((int) Cache::get($expectedKey))->toBe(1);
});
