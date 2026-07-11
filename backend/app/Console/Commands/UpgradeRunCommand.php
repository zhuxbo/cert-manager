<?php

namespace App\Console\Commands;

use App\Services\Upgrade\UpgradeService;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class UpgradeRunCommand extends Command
{
    protected $signature = 'upgrade:run {version=latest}';

    protected $description = '执行系统升级';

    public function handle(UpgradeService $upgradeService, UpgradeStatusManager $statusManager): int
    {
        $version = $this->argument('version');

        // 升级流程已迁移到 upgrade.sh / 后台覆盖式，
        // upgrade:run 仅作兼容入口保留；不推荐使用，改用：
        //  - 宝塔模式：管理后台 → 系统设置 → 在线升级
        //  - 命令行：./upgrade.sh --version <ver>
        $this->warn('upgrade:run is deprecated; use upgrade.sh or admin Web UI instead.');

        Log::info("[Upgrade] 开始升级到版本: $version");
        $this->info("开始升级到版本: $version");

        // 标记升级开始
        $statusManager->start($version);

        // Fatal error 兜底：try-finally 挡不住 Class not found / 内存溢出等 fatal，
        // 用 register_shutdown_function 捕获 error_get_last，避免 status.json 卡 running。
        // 处理体抽成 handleFatalShutdown（静态、注入 $err）便于对 fatal 自愈路径直测。
        register_shutdown_function(static function () use ($statusManager) {
            self::handleFatalShutdown($statusManager, error_get_last());
        });

        try {
            // 执行升级，传入状态管理器用于实时更新进度
            $result = $upgradeService->performUpgradeWithStatus($version, $statusManager);

            if ($result['success']) {
                Log::info('[Upgrade] 升级成功！');
                $this->info('升级成功！');

                return Command::SUCCESS;
            } else {
                $error = $result['error'] ?? '未知错误';
                Log::error("[Upgrade] 升级失败: $error");
                $this->error("升级失败: $error");

                // 确保状态被标记为失败（performUpgradeWithStatus 内部应该已经调用了）
                // 但为了安全起见，再次确认状态
                $currentStatus = $statusManager->get();
                if ($currentStatus && $currentStatus['status'] !== 'failed') {
                    $statusManager->fail($error);
                }

                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            // \Throwable 纯防御：UpgradeService 已就地接住 \Error，此处兜住任何冒泡异常。
            Log::error("[Upgrade] 升级异常: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            $statusManager->fail($e->getMessage());
            $this->error('升级异常: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Fatal error 退出时的兜底自愈（经 register_shutdown_function 触发）。
     *
     * try-finally / catch(\Throwable) 挡不住的真 fatal（OOM / Class not found / E_PARSE /
     * E_COMPILE_ERROR）会绕过 handle() 的 catch，仅此处能接住：置 status=failed + 解冻 + 解维护。
     * 双守卫（非 fatal 早退 + 非 running 早退）保证正常成功路径不触发（SIGKILL 走不到这里，由 watchdog 兜底）。
     * 抽成命名静态方法（注入 $err）便于对该路径直测。
     *
     * @param  array{type:int,message:string,file:string,line:int}|null  $err  error_get_last() 结果
     */
    public static function handleFatalShutdown(UpgradeStatusManager $statusManager, ?array $err): void
    {
        if (! $err || ! in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR], true)) {
            return;
        }
        if (! $statusManager->isRunning()) {
            return;
        }
        try {
            $statusManager->fail(sprintf(
                '升级进程异常退出（fatal error）: %s (%s:%d)',
                $err['message'],
                $err['file'],
                $err['line'],
            ));
            // 先解冻再 up —— 对齐 UpgradeService / UpgradeWatchdogCommand 的「unfreeze 严格先于 up」契约：
            // up 解除 down 并唤醒被暂停的 worker 去 pop job，若 freeze 仍在，SkipWhenUpgradeFrozen 的
            // release(60) 会每 60s 烧一次 attempts（tries=5 job ~5min 全落 failed），非白名单 HTTP 503
            // 滞留至 freeze TTL(7200s)；而 fail() 已置 status=failed → watchdog 不再兜。
            // unfreeze() 返回 void 且内部吞 Throwable，best-effort、绝不挡后续 up。
            UpgradeFreezeLock::unfreeze();
            Artisan::call('up');
        } catch (\Throwable $t) {
            // shutdown 阶段尽量静默，写日志兜底
            Log::error('[Upgrade] shutdown handler 写 status / 解维护失败: '.$t->getMessage());
        }
    }
}
