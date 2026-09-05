<?php

use App\Utils\UpgradeFreezeLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses()->group('database');

beforeEach(function () {
    UpgradeFreezeLock::unfreeze('restore');
    Cache::store('runtime')->forget('schedule:heartbeat');
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze('restore');
    Cache::store('runtime')->forget('schedule:heartbeat');
    Carbon::setTestNow();
});

test('schedule:heartbeat 写入近 now 的时间戳到 Cache', function () {
    $before = now()->timestamp;

    $this->artisan('schedule:heartbeat')->assertSuccessful();

    $stored = Cache::store('runtime')->get('schedule:heartbeat');
    expect($stored)->not->toBeNull()
        ->and((int) $stored)->toBeGreaterThanOrEqual($before)
        ->and((int) $stored)->toBeLessThanOrEqual(now()->timestamp + 1);
});

test('重复跑 schedule:heartbeat 刷新时间戳（心跳续期，供 /api/health 判活）', function () {
    $this->artisan('schedule:heartbeat')->assertSuccessful();
    $first = (int) Cache::store('runtime')->get('schedule:heartbeat');

    // 让 app 时间前进 120s，再跑一次心跳应刷新时间戳（死 scheduler 则不会刷新 → health 判 stale）
    Carbon::setTestNow(now()->addSeconds(120));
    $this->artisan('schedule:heartbeat')->assertSuccessful();
    $second = (int) Cache::store('runtime')->get('schedule:heartbeat');

    expect($second)->toBeGreaterThan($first);
});

test('schedule:heartbeat 在恢复冻结期只刷新心跳且不移除 restore owner 锁', function () {
    UpgradeFreezeLock::freezeRestore('atomic restore');

    $this->artisan('schedule:heartbeat')->assertSuccessful();

    expect(Cache::store('runtime')->get('schedule:heartbeat'))->not->toBeNull()
        ->and(UpgradeFreezeLock::info()['owner_source'] ?? null)->toBe('restore')
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue();
});
