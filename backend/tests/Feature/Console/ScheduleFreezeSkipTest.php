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

    // 全部 Schedule::command（E 批监控/H 批 watchdog 扩容后 18 个）+ 1 个 Artisan::command('inspire')；
    // 断言只取下限 >=7 防注册面回退、不锁具体数量（新增命令无需改此处），逐 event 检查才是主断言
    expect(count($events))->toBeGreaterThanOrEqual(7);

    foreach ($events as $event) {
        $description = $event->description ?? $event->command ?? 'unknown';

        // 有意 freeze 存活者（不挂 skip($skipWhenFrozen)，见 console.php 注释）：
        //   - upgrade:watchdog：升级进程死后自愈命令，freeze 期恰是它收拾残局之时；
        //   - schedule:heartbeat：M1 心跳，freeze 期若停则 /api/health 误判 stale 503 → 拨测/外部监控误报。
        // 二者冻结期均 filtersPass=true。
        $command = (string) ($event->command ?? '');
        if (str_contains($command, 'upgrade:watchdog') || str_contains($command, 'schedule:heartbeat')) {
            expect($event->filtersPass($this->app))
                ->toBeTrue("freeze 期存活命令必须 filtersPass=true: $description");

            continue;
        }

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
