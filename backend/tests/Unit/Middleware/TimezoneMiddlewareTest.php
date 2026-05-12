<?php

use App\Http\Middleware\TimezoneMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->originalAppTz = config('app.timezone');
    $this->originalPhpTz = date_default_timezone_get();
});

afterEach(function () {
    Config::set('app.timezone', $this->originalAppTz);
    date_default_timezone_set($this->originalPhpTz);
});

test('TimezoneMiddleware 不修改 PHP 全局时区（写入侧由系统时区固定）', function () {
    Config::set('app.timezone', 'Asia/Shanghai');
    date_default_timezone_set('Asia/Shanghai');

    $middleware = new TimezoneMiddleware;
    $request = Request::create('/test', 'GET');
    $request->headers->set('X-Timezone', 'America/New_York');

    $middleware->handle($request, fn ($req) => response('ok'));

    expect(date_default_timezone_get())->toBe('Asia/Shanghai');
});

test('TimezoneMiddleware 按 X-Timezone 头设置 app.timezone（用于序列化输出）', function () {
    Config::set('app.timezone', 'Asia/Shanghai');

    $middleware = new TimezoneMiddleware;
    $request = Request::create('/test', 'GET');
    $request->headers->set('X-Timezone', 'America/New_York');

    $middleware->handle($request, fn ($req) => response('ok'));

    expect(config('app.timezone'))->toBe('America/New_York');
});

test('TimezoneMiddleware 缺失 X-Timezone 头时不修改 app.timezone', function () {
    Config::set('app.timezone', 'Europe/London');

    $middleware = new TimezoneMiddleware;
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, fn ($req) => response('ok'));

    expect(config('app.timezone'))->toBe('Europe/London');
});

test('TimezoneMiddleware fallback 默认值来自 config(app.timezone)，不硬编码 Asia/Shanghai', function () {
    // 模拟部署在 UTC 时区的系统
    Config::set('app.timezone', 'UTC');

    $middleware = new TimezoneMiddleware;
    $request = Request::create('/test', 'GET');
    // 不设 X-Timezone 头（如支付平台异步回调）

    $middleware->handle($request, fn ($req) => response('ok'));

    // fallback 应该是 UTC，而不是硬编码的 Asia/Shanghai
    expect(config('app.timezone'))->toBe('UTC');
});

test('TimezoneMiddleware 仅影响 app.timezone 配置，不影响 PHP 全局时区', function () {
    Config::set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $middleware = new TimezoneMiddleware;
    $request = Request::create('/test', 'GET');
    $request->headers->set('X-Timezone', 'Europe/Paris');

    $middleware->handle($request, fn ($req) => response('ok'));

    expect(config('app.timezone'))->toBe('Europe/Paris');
    expect(date_default_timezone_get())->toBe('UTC');
});
