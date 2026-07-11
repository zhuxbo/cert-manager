<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses()->group('database');

beforeEach(function () {
    Cache::forget('schedule:heartbeat');
});

afterEach(function () {
    Cache::forget('schedule:heartbeat');
    Carbon::setTestNow();
});

test('schedule:heartbeat 写入近 now 的时间戳到 Cache', function () {
    $before = now()->timestamp;

    $this->artisan('schedule:heartbeat')->assertSuccessful();

    $stored = Cache::get('schedule:heartbeat');
    expect($stored)->not->toBeNull()
        ->and((int) $stored)->toBeGreaterThanOrEqual($before)
        ->and((int) $stored)->toBeLessThanOrEqual(now()->timestamp + 1);
});

test('重复跑 schedule:heartbeat 刷新时间戳（心跳续期，供 /api/health 判活）', function () {
    $this->artisan('schedule:heartbeat')->assertSuccessful();
    $first = (int) Cache::get('schedule:heartbeat');

    // 让 app 时间前进 120s，再跑一次心跳应刷新时间戳（死 scheduler 则不会刷新 → health 判 stale）
    Carbon::setTestNow(now()->addSeconds(120));
    $this->artisan('schedule:heartbeat')->assertSuccessful();
    $second = (int) Cache::get('schedule:heartbeat');

    expect($second)->toBeGreaterThan($first);
});
