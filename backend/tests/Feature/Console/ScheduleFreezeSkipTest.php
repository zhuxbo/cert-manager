<?php

use App\Utils\UpgradeFreezeLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

uses()->group('database');

beforeEach(function () {
    // withoutOverlapping() 依赖 cache mutex；清空避免历史残留 mutex 影响 filtersPass 结果
    Cache::flush();
    UpgradeFreezeLock::unfreeze();
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze();
    Cache::flush();
});

// ==========================================
// freeze 期间所有 Schedule event 必须 skip（filtersPass=false）
// ==========================================

test('upgrade freeze 期间所有 Schedule event 都被跳过', function () {
    UpgradeFreezeLock::freeze('1.0.0', '1.1.0', 60);
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);
    $events = $schedule->events();

    // 6 个 Schedule::command + 1 个 Artisan::command('inspire')->hourly()
    expect(count($events))->toBeGreaterThanOrEqual(7);

    foreach ($events as $event) {
        $description = $event->description ?? $event->command ?? 'unknown';
        expect($event->filtersPass($this->app))
            ->toBeFalse("event 应在 freeze 期间被 skip，但 filtersPass 返回 true: $description");
    }
});

// ==========================================
// 未 freeze 时 event 正常 pass（除 withoutOverlapping mutex 外，filter 默认 pass）
// ==========================================

test('未 freeze 时所有 Schedule event 正常通过 filter', function () {
    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();

    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);
    $events = $schedule->events();

    expect(count($events))->toBeGreaterThanOrEqual(7);

    foreach ($events as $event) {
        $description = $event->description ?? $event->command ?? 'unknown';
        expect($event->filtersPass($this->app))
            ->toBeTrue("未 freeze 时 event 应通过 filter，但 filtersPass 返回 false: $description");
    }
});
