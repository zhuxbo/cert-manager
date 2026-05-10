<?php

use App\Http\Middleware\TimezoneMiddleware;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->originalTz = config('app.timezone');
    $this->originalPhpTz = date_default_timezone_get();
});

afterEach(function () {
    Config::set('app.timezone', $this->originalTz);
    date_default_timezone_set($this->originalPhpTz);
});

// ==========================================
// 写入恒定：无论 X-Timezone 头是什么，写入永远基于系统时区
// ==========================================

test('中间件运行后 PHP 全局时区不被 X-Timezone 头污染', function () {
    Config::set('app.timezone', 'Asia/Shanghai');
    date_default_timezone_set('Asia/Shanghai');

    $middleware = new TimezoneMiddleware;
    $request = Request::create('/test', 'POST');
    $request->headers->set('X-Timezone', 'America/New_York');

    $middleware->handle($request, fn () => response('ok'));

    expect(date_default_timezone_get())->toBe('Asia/Shanghai');
});

test('多个不同时区的请求串行处理后不污染 PHP 全局时区', function () {
    Config::set('app.timezone', 'Asia/Shanghai');
    date_default_timezone_set('Asia/Shanghai');

    $middleware = new TimezoneMiddleware;
    foreach (['America/New_York', 'Europe/Paris', 'UTC', 'Asia/Tokyo'] as $userTz) {
        $request = Request::create('/test', 'POST');
        $request->headers->set('X-Timezone', $userTz);
        $middleware->handle($request, fn () => response('ok'));
    }

    expect(date_default_timezone_get())->toBe('Asia/Shanghai');
});

test('用户写入时模拟 X-Timezone 头存在，数据库 raw 值仍按系统时区', function () {
    Config::set('app.timezone', 'Asia/Shanghai');
    date_default_timezone_set('Asia/Shanghai');

    $middleware = new TimezoneMiddleware;
    $request = Request::create('/test', 'POST');
    $request->headers->set('X-Timezone', 'America/New_York');

    $userId = null;
    $middleware->handle($request, function () use (&$userId) {
        // 模拟在中间件之后创建用户（写入数据库）
        $userId = User::factory()->create()->id;

        return response('ok');
    });

    // 读 raw 数据库值（绕过 Eloquent 序列化）
    $raw = DB::table('users')->where('id', $userId)->value('created_at');
    $rawCarbon = Carbon::parse($raw, 'Asia/Shanghai');

    // raw 写入字符串接近 PHP now() 系统时区下的当前值，绝不是纽约时间
    expect($rawCarbon->diffInMinutes(now()))->toBeLessThan(1);
});

// ==========================================
// 序列化：同一记录在不同 X-Timezone 下输出对应偏移
// ==========================================

test('同一 user 在不同 app.timezone 下输出对应偏移的 ISO8601', function () {
    Config::set('app.timezone', 'Asia/Shanghai');

    $user = User::factory()->create();
    // 直接 update raw 值，避开任何时区转换
    DB::table('users')->where('id', $user->id)->update([
        'created_at' => '2026-05-05 21:43:56',
    ]);

    Config::set('app.timezone', 'Asia/Shanghai');
    $shanghai = User::find($user->id)->toArray()['created_at'];

    Config::set('app.timezone', 'America/New_York');
    $newyork = User::find($user->id)->toArray()['created_at'];

    Config::set('app.timezone', 'UTC');
    $utc = User::find($user->id)->toArray()['created_at'];

    expect($shanghai)->toContain('+08:00');
    expect($newyork)->toMatch('/-0[45]:00/');
    expect($utc)->toContain('+00:00');

    // 三者解析后指向同一个 UTC 时刻
    expect(Carbon::parse($shanghai)->utc()->toDateTimeString())
        ->toBe(Carbon::parse($newyork)->utc()->toDateTimeString())
        ->toBe(Carbon::parse($utc)->utc()->toDateTimeString());
});

// ==========================================
// 数据库连接 timezone 与 app.timezone 偏移一致（仅 mysql）
// ==========================================

test('数据库连接 timezone 偏移与 app.timezone 一致（mysql 数字偏移）', function () {
    $appTz = config('app.timezone');
    $appOffset = (new DateTimeZone($appTz))->getOffset(new DateTime);

    $mysqlTz = config('database.connections.mysql.timezone');

    expect((new DateTimeZone($mysqlTz))->getOffset(new DateTime))->toBe($appOffset);
});
