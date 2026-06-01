<?php

use App\Exceptions\ApiResponseException;
use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugins\Easy\Middleware\EasyRateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function easyRl(): EasyRateLimiter
{
    return new EasyRateLimiter;
}

function passThrough(): Closure
{
    return fn () => new JsonResponse(['code' => 1]);
}

function setEasyRlSetting(string $key, mixed $value): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => 'Site', 'weight' => 0]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => $key],
        ['value' => $value, 'type' => 'string']
    );
    Cache::flush();
}

test('正常请求透传', function () {
    $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-OK', 'email' => 'a@example.com']);

    $response = easyRl()->handle($request, passThrough());

    expect($response->getData(true)['code'])->toBe(1);
});

test('tid 维度超限被拦截', function () {
    setEasyRlSetting('easyRateLimitTidMax', 3);
    setEasyRlSetting('easyRateLimitIpMax', 1000);

    // 前 3 次放行
    for ($i = 0; $i < 3; $i++) {
        $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-A', 'email' => 'a@example.com']);
        easyRl()->handle($request, passThrough());
    }

    // 第 4 次（tid 维度）被拦截
    $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-A', 'email' => 'a@example.com']);
    easyRl()->handle($request, passThrough());
})->throws(ApiResponseException::class, '操作过于频繁');

test('不同 tid 计数互不影响', function () {
    setEasyRlSetting('easyRateLimitTidMax', 2);
    setEasyRlSetting('easyRateLimitIpMax', 1000);

    // tid A 打满
    for ($i = 0; $i < 2; $i++) {
        $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-A']);
        easyRl()->handle($request, passThrough());
    }

    // tid B 不受影响
    $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-B']);
    $response = easyRl()->handle($request, passThrough());

    expect($response->getData(true)['code'])->toBe(1);
});

test('IP 维度兜底（即使换 tid 也拦截枚举）', function () {
    setEasyRlSetting('easyRateLimitTidMax', 1000);
    setEasyRlSetting('easyRateLimitIpMax', 2);

    // 每次换不同 tid，但同一 IP，靠 IP 维度限流
    for ($i = 0; $i < 2; $i++) {
        $request = Request::create('/api/easy/check', 'POST', ['tid' => "TID-$i"]);
        easyRl()->handle($request, passThrough());
    }

    $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-X']);
    easyRl()->handle($request, passThrough());
})->throws(ApiResponseException::class, '操作过于频繁');

test('未传 tid 时仅 IP 维度计数', function () {
    setEasyRlSetting('easyRateLimitTidMax', 1);
    setEasyRlSetting('easyRateLimitIpMax', 3);

    // 不传 tid，tid 维度不计数；连续 3 次靠 IP 兜底放行
    for ($i = 0; $i < 3; $i++) {
        $request = Request::create('/api/easy/check', 'POST');
        $response = easyRl()->handle($request, passThrough());
        expect($response->getData(true)['code'])->toBe(1);
    }

    // 第 4 次 IP 维度超限
    expect(fn () => easyRl()->handle(Request::create('/api/easy/check', 'POST'), passThrough()))
        ->toThrow(ApiResponseException::class);
});

test('缺省配置走常量默认值', function () {
    // 不写任何 setting，应使用常量默认（tid 30 / ip 60）
    expect(EasyRateLimiter::DEFAULT_TID_MAX)->toBe(30);
    expect(EasyRateLimiter::DEFAULT_IP_MAX)->toBe(60);

    // 打满到常量上限不报错（DEFAULT_TID_MAX 次），第 31 次 tid 维度被拦截
    for ($i = 0; $i < EasyRateLimiter::DEFAULT_TID_MAX; $i++) {
        $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-DEFAULT', 'email' => 'a@example.com']);
        easyRl()->handle($request, passThrough());
    }

    expect(fn () => easyRl()->handle(
        Request::create('/api/easy/check', 'POST', ['tid' => 'TID-DEFAULT', 'email' => 'a@example.com']),
        passThrough()
    ))->toThrow(ApiResponseException::class);
})->skip(
    EasyRateLimiter::DEFAULT_IP_MAX < EasyRateLimiter::DEFAULT_TID_MAX,
    'IP 上限低于 tid 上限时此用例不适用'
);

test('非法 setting 值回落默认', function () {
    setEasyRlSetting('easyRateLimitTidMax', 'abc');

    // 非数字 → 回落 DEFAULT_TID_MAX(30)，前 4 次（远低于 30）应放行
    for ($i = 0; $i < 4; $i++) {
        $request = Request::create('/api/easy/check', 'POST', ['tid' => 'TID-LEGACY', 'email' => 'a@example.com']);
        $response = easyRl()->handle($request, passThrough());
        expect($response->getData(true)['code'])->toBe(1);
    }
});
