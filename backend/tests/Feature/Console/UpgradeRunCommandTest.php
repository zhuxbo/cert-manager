<?php

use App\Console\Commands\UpgradeRunCommand;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Support\Facades\Artisan;

/**
 * UpgradeRunCommand::handleFatalShutdown —— 真 fatal（OOM / Class not found / E_PARSE /
 * E_COMPILE_ERROR）退出时经 register_shutdown_function 触发的兜底自愈。
 *
 * 契约：fail(status=failed) → unfreeze（严格先于 up）→ up。
 * up 解除 down 并唤醒被暂停的 worker 去 pop job；若 freeze 仍在，SkipWhenUpgradeFrozen 的
 * release(60) 会每 60s 烧一次 attempts、非白名单 HTTP 503 滞留至 freeze TTL，且 fail() 置
 * status=failed 后 watchdog 不再兜。故此路径必须解冻。用真实 down/freeze + isDownForMaintenance
 * 观测（对齐 UpgradeWatchdogCommandTest），机器验证「解冻 + 解维护」双落地。
 */
beforeEach(function () {
    (new UpgradeStatusManager)->clear();
    UpgradeFreezeLock::unfreeze();
});

afterEach(function () {
    (new UpgradeStatusManager)->clear();
    UpgradeFreezeLock::unfreeze();
    try {
        Artisan::call('up');
    } catch (Throwable) {
    }
});

/** 合成一条 fatal error_get_last() 结构 */
function urcFatalErr(): array
{
    return [
        'type' => E_ERROR,
        'message' => '模拟 fatal（OOM / Class not found）',
        'file' => '/tmp/x.php',
        'line' => 1,
    ];
}

test('running 期 fatal：fail + 解冻 + 解维护（unfreeze 严格先于 up）', function () {
    // 危险窗态：down + freeze + status=running
    Artisan::call('down', ['--retry' => 60]);
    UpgradeFreezeLock::freeze('v1.0.0', 'v1.1.0', 3600);
    $sm = new UpgradeStatusManager;
    $sm->start('v1.1.0'); // pid=getmypid()（测试进程存活）→ isRunning=true

    expect($this->app->isDownForMaintenance())->toBeTrue()
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and($sm->isRunning())->toBeTrue();

    UpgradeRunCommand::handleFatalShutdown($sm, urcFatalErr());

    expect((new UpgradeStatusManager)->get()['status'])->toBe('failed')
        ->and(UpgradeFreezeLock::isFrozen())->toBeFalse()       // freeze 已清除（缺 unfreeze 时此断言红）
        ->and($this->app->isDownForMaintenance())->toBeFalse(); // 维护已解
});

test('非 fatal（E_WARNING / null）早退：freeze / 维护 / status 均不动', function () {
    UpgradeFreezeLock::freeze('v1.0.0', 'v1.1.0', 3600);
    $sm = new UpgradeStatusManager;
    $sm->start('v1.1.0');

    UpgradeRunCommand::handleFatalShutdown($sm, ['type' => E_WARNING, 'message' => 'w', 'file' => 'x', 'line' => 1]);
    UpgradeRunCommand::handleFatalShutdown($sm, null);

    expect((new UpgradeStatusManager)->get()['status'])->toBe('running')
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue();
});

test('非 running 早退：正常成功路径（status 非 running）不误清冻结', function () {
    UpgradeFreezeLock::freeze('v1.0.0', 'v1.1.0', 3600);
    // 未 start → status 文件不存在 → isRunning()=false
    $sm = new UpgradeStatusManager;

    UpgradeRunCommand::handleFatalShutdown($sm, urcFatalErr());

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue() // 早退，未误清
        ->and((new UpgradeStatusManager)->get())->toBeNull();
});
