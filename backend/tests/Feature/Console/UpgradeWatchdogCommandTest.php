<?php

use App\Services\Notification\SystemAlert;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

/**
 * H1 upgrade:watchdog —— 升级进程被硬杀（SIGKILL/OOM/\Error）后自愈：
 * time-stale 且 PID 死 → artisan up + unfreeze + status failed + 去重 SystemAlert。
 * PID 存活是「不动作」一票否决（慢单步不误 up 半迁移库）。
 */

/** 确定性死 PID */
function wdDeadPid(): int
{
    $proc = proc_open('exit 0', [], $pipes);
    $pid = (int) proc_get_status($proc)['pid'];
    proc_close($proc);

    return $pid;
}

/** 直接写 status.json 构造受控 pid / 时间戳 */
function wdWriteStatus(array $overrides): void
{
    $file = storage_path('upgrades/status.json');
    if (! is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    $base = [
        'status' => 'running',
        'version' => 'v9.9.9',
        'started_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
        'pid' => getmypid(),
        'steps' => [],
        'progress' => 0,
    ];
    file_put_contents($file, json_encode(array_merge($base, $overrides)));
}

/** 捕获型 SystemAlert，记录 send/clearDedupe 调用 */
function wdSpySystemAlert(): object
{
    $spy = new class
    {
        public int $sendCount = 0;

        public array $lastArgs = [];
    };
    $mock = Mockery::mock(SystemAlert::class);
    $mock->shouldReceive('send')->andReturnUsing(function (...$args) use ($spy) {
        $spy->sendCount++;
        $spy->lastArgs = $args;

        return true;
    });
    $mock->shouldReceive('clearDedupe')->andReturnNull();
    app()->instance(SystemAlert::class, $mock);

    return $spy;
}

beforeEach(function () {
    Config::set('upgrade.stale_seconds', 3600);
    (new UpgradeStatusManager)->clear();
    UpgradeFreezeLock::unfreeze();
});

afterEach(function () {
    Mockery::close();
    (new UpgradeStatusManager)->clear();
    UpgradeFreezeLock::unfreeze();
    try {
        Artisan::call('up');
    } catch (Throwable) {
    }
});

test('① running + 超时 + PID 死 → up + status failed + unfreeze + SystemAlert 一次', function () {
    // 预置 down（本仓已删 PreventRequestsDuringMaintenance，down 不挡 HTTP，仅供本测试观测 up 是否被调）
    Artisan::call('down', ['--retry' => 60]);
    expect($this->app->isDownForMaintenance())->toBeTrue();

    UpgradeFreezeLock::freeze('v9.9.8', 'v9.9.9', 3600);
    wdWriteStatus([
        'pid' => wdDeadPid(),
        'started_at' => now()->subHours(2)->toDateTimeString(),
        'updated_at' => now()->subHours(2)->toDateTimeString(),
    ]);

    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('failed')
        ->and($this->app->isDownForMaintenance())->toBeFalse() // watchdog 调了 artisan up
        ->and(UpgradeFreezeLock::isFrozen())->toBeFalse()      // 并解冻
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->lastArgs[0])->toBe('upgrade')             // category
        ->and($spy->lastArgs[4])->toBe('upgrade_watchdog');   // dedupeKey
});

test('② running + 超时但 PID 活 → 无动作、status 仍 running、无告警', function () {
    wdWriteStatus([
        'pid' => getmypid(),
        'started_at' => now()->subHours(2)->toDateTimeString(),
        'updated_at' => now()->subHours(2)->toDateTimeString(),
    ]);
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('running')
        ->and($spy->sendCount)->toBe(0);
});

test('③ 心跳新鲜 → no-op', function () {
    wdWriteStatus(['pid' => wdDeadPid(), 'updated_at' => now()->toDateTimeString()]);
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('running')
        ->and($spy->sendCount)->toBe(0);
});

test('③b 无 status → no-op', function () {
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect($spy->sendCount)->toBe(0);
});

test('③c completed 状态 → no-op（不误解正常升级的维护）', function () {
    wdWriteStatus([
        'status' => 'completed',
        'pid' => wdDeadPid(),
        'updated_at' => now()->subHours(5)->toDateTimeString(),
    ]);
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect($spy->sendCount)->toBe(0);
});

test('④ console.php 注册 upgrade:watchdog：evenInMaintenanceMode 且 freeze 期不被 skip', function () {
    UpgradeFreezeLock::freeze('1.0.0', '1.1.0', 60);

    $schedule = $this->app->make(Schedule::class);
    $event = collect($schedule->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'upgrade:watchdog'));

    expect($event)->not->toBeNull();
    expect($event->evenInMaintenanceMode)->toBeTrue();
    // 自愈命令必须在冻结期存活：未挂 skip($skipWhenFrozen)，filtersPass 仍为 true
    expect($event->filtersPass($this->app))->toBeTrue();

    UpgradeFreezeLock::unfreeze();
});
