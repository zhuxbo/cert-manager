<?php

use App\Exceptions\ApiResponseException;
use App\Http\Middleware\VerifyCodeRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('verify_code_rl:reset-password:email:victim@example.com');
    RateLimiter::clear('verify_code_rl:reset-password:ip:127.0.0.1');
});

test('VerifyCodeRateLimiter 正常请求透传', function () {
    $middleware = new VerifyCodeRateLimiter;
    $request = Request::create('/api/reset-password', 'POST', ['email' => 'victim@example.com']);

    $response = $middleware->handle($request, function () {
        return new JsonResponse(['code' => 1]);
    }, 'reset-password');

    expect($response->getData(true)['code'])->toBe(1);
});

test('VerifyCodeRateLimiter email 维度超限被拦截', function () {
    config()->set('auth.verify_code_rate_limiter.default', [
        'email_max' => 3,
        'email_decay' => 10,
        'ip_max' => 1000,
        'ip_decay' => 10,
    ]);

    $middleware = new VerifyCodeRateLimiter;

    // 前 3 次放行
    for ($i = 0; $i < 3; $i++) {
        $request = Request::create('/api/reset-password', 'POST', ['email' => 'victim@example.com']);
        $middleware->handle($request, fn () => new JsonResponse(['code' => 0]), 'reset-password');
    }

    // 第 4 次（email 维度）被拦截
    $request = Request::create('/api/reset-password', 'POST', ['email' => 'victim@example.com']);
    $middleware->handle($request, fn () => new JsonResponse(['code' => 0]), 'reset-password');
})->throws(ApiResponseException::class);

test('VerifyCodeRateLimiter 不同 email 计数互不影响', function () {
    config()->set('auth.verify_code_rate_limiter.default', [
        'email_max' => 2,
        'email_decay' => 10,
        'ip_max' => 1000,
        'ip_decay' => 10,
    ]);

    $middleware = new VerifyCodeRateLimiter;

    // email A 打满
    for ($i = 0; $i < 2; $i++) {
        $request = Request::create('/api/reset-password', 'POST', ['email' => 'a@example.com']);
        $middleware->handle($request, fn () => new JsonResponse(['code' => 0]), 'reset-password');
    }

    // email B 不受影响
    $request = Request::create('/api/reset-password', 'POST', ['email' => 'b@example.com']);
    $response = $middleware->handle($request, fn () => new JsonResponse(['code' => 1]), 'reset-password');

    expect($response->getData(true)['code'])->toBe(1);
});

test('VerifyCodeRateLimiter IP 维度兜底（无 email 时）', function () {
    config()->set('auth.verify_code_rate_limiter.default', [
        'email_max' => 1000,
        'email_decay' => 10,
        'ip_max' => 2,
        'ip_decay' => 10,
    ]);

    $middleware = new VerifyCodeRateLimiter;

    // 不传 email，仅靠 IP 维度限流
    for ($i = 0; $i < 2; $i++) {
        $request = Request::create('/api/send-email-code', 'POST');
        $middleware->handle($request, fn () => new JsonResponse(['code' => 1]), 'send-email-code');
    }

    $request = Request::create('/api/send-email-code', 'POST');
    $middleware->handle($request, fn () => new JsonResponse(['code' => 1]), 'send-email-code');
})->throws(ApiResponseException::class);

test('VerifyCodeRateLimiter email 大小写归一化', function () {
    config()->set('auth.verify_code_rate_limiter.default', [
        'email_max' => 1,
        'email_decay' => 10,
        'ip_max' => 1000,
        'ip_decay' => 10,
    ]);

    $middleware = new VerifyCodeRateLimiter;

    // 小写打满 1 次
    $request = Request::create('/api/reset-password', 'POST', ['email' => 'victim@example.com']);
    $middleware->handle($request, fn () => new JsonResponse(['code' => 0]), 'reset-password');

    // 大写视为同一 key，应被拦截
    $request = Request::create('/api/reset-password', 'POST', ['email' => 'VICTIM@EXAMPLE.COM']);
    $middleware->handle($request, fn () => new JsonResponse(['code' => 0]), 'reset-password');
})->throws(ApiResponseException::class);
