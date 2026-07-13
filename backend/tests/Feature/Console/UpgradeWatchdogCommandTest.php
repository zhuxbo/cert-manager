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

/** 直接写 upgrade.lock 构造受控归属/时间（绕过 freeze() 的 getmypid 自动捕获） */
function wdWriteLock(array $overrides = []): void
{
    $base = [
        'frozen_at' => now()->toIso8601String(),
        'version_from' => 'v9.9.8',
        'version_to' => 'v9.9.9',
        'ttl_seconds' => 3600,
    ];
    file_put_contents(UpgradeFreezeLock::path(), json_encode(array_merge($base, $overrides)));
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

    // 锁归属死升级本体（owner=web 且 pid 同 status.pid），与真实 SIGKILL 现场一致
    $deadPid = wdDeadPid();
    wdWriteLock([
        'frozen_at' => now()->subHours(2)->toIso8601String(),
        'owner_source' => 'web',
        'owner_pid' => $deadPid,
    ]);
    wdWriteStatus([
        'pid' => $deadPid,
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

test('⑤ 杀手场景：shell 锁（upgrade.sh 升级中）→ 零动作，仅 foreign 去重告警', function () {
    Artisan::call('down', ['--retry' => 60]);
    wdWriteStatus([
        'pid' => wdDeadPid(),
        'started_at' => now()->subHours(2)->toDateTimeString(),
        'updated_at' => now()->subHours(2)->toDateTimeString(),
    ]);
    // shell 路径：artisan upgrade:freeze 子进程写锁后即退出（owner_pid 与 status.pid 无关）
    wdWriteLock(['owner_source' => 'shell', 'owner_pid' => 99999999]);
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('running') // 不 fail
        ->and($this->app->isDownForMaintenance())->toBeTrue()            // 不 up
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue()                 // 不 unfreeze
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->lastArgs[4])->toBe('upgrade_watchdog_foreign');
});

test('⑥ 杀手场景直译：无主旧格式锁且 frozen_at 晚于死升级心跳 → 零动作（N-1 版 upgrade.sh 首跑）', function () {
    Artisan::call('down', ['--retry' => 60]);
    wdWriteStatus([
        'pid' => wdDeadPid(),
        'started_at' => now()->subHours(2)->toDateTimeString(),
        'updated_at' => now()->subHours(2)->toDateTimeString(),
    ]);
    wdWriteLock([]); // frozen_at=now、无 owner 字段：旧版 artisan 在切码前写的锁
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('running')
        ->and($this->app->isDownForMaintenance())->toBeTrue()
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->lastArgs[4])->toBe('upgrade_watchdog_foreign');
});

test('⑦ 无主旧格式锁且 frozen_at 与心跳同刻（freeze 后立刻被杀）→ 自愈照旧不回退', function () {
    Artisan::call('down', ['--retry' => 60]);
    $t = now()->subHours(2);
    wdWriteStatus([
        'pid' => wdDeadPid(),
        'started_at' => $t->toDateTimeString(),
        'updated_at' => $t->toDateTimeString(),
    ]);
    wdWriteLock(['frozen_at' => $t->toIso8601String()]);
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('failed')
        ->and($this->app->isDownForMaintenance())->toBeFalse()
        ->and(UpgradeFreezeLock::isFrozen())->toBeFalse()
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->lastArgs[4])->toBe('upgrade_watchdog');
});

test('⑧ web 锁但 owner_pid 与 status.pid 不符 → 零动作（身份不符不动）', function () {
    Artisan::call('down', ['--retry' => 60]);
    wdWriteStatus([
        'pid' => wdDeadPid(),
        'started_at' => now()->subHours(2)->toDateTimeString(),
        'updated_at' => now()->subHours(2)->toDateTimeString(),
    ]);
    wdWriteLock(['owner_source' => 'web', 'owner_pid' => 99999999]);
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('running')
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->lastArgs[4])->toBe('upgrade_watchdog_foreign');
});

test('⑨ stale 且无冻结锁 → 自愈照旧（shell 升级结束解锁后的收敛路径）', function () {
    wdWriteStatus([
        'pid' => wdDeadPid(),
        'started_at' => now()->subHours(2)->toDateTimeString(),
        'updated_at' => now()->subHours(2)->toDateTimeString(),
    ]);
    $spy = wdSpySystemAlert();

    $this->artisan('upgrade:watchdog')->assertSuccessful();

    expect((new UpgradeStatusManager)->get()['status'])->toBe('failed')
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->lastArgs[4])->toBe('upgrade_watchdog');
});
