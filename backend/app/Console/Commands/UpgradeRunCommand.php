<?php

namespace App\Console\Commands;

use App\Services\Upgrade\UpgradeService;
use App\Services\Upgrade\UpgradeStatusManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpgradeRunCommand extends Command
{
    protected $signature = 'upgrade:run {version=latest}';

    protected $description = '执行系统升级';

    public function handle(UpgradeService $upgradeService, UpgradeStatusManager $statusManager): int
    {
        $version = $this->argument('version');

        // 升级流程已迁移到 upgrade.sh / 后台覆盖式（freeze + smoke + opcache 链路），
        // upgrade:run 仅作兼容入口保留；不推荐使用，改用：
        //  - 宝塔模式：管理后台 → 系统设置 → 在线升级
        //  - 命令行：./upgrade.sh --version <ver>
        $this->warn('upgrade:run is deprecated; use upgrade.sh or admin Web UI instead.');

        Log::info("[Upgrade] 开始升级到版本: $version");
        $this->info("开始升级到版本: $version");

        // 标记升级开始
        $statusManager->start($version);

        // Fatal error 兜底：try-finally 挡不住 Class not found / 内存溢出等 fatal，
        // 用 register_shutdown_function 捕获 error_get_last，避免 status.json 卡 running
        register_shutdown_function(static function () use ($statusManager) {
            $err = error_get_last();
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
            } catch (\Throwable $t) {
                // shutdown 阶段尽量静默，写日志兜底
                Log::error('[Upgrade] shutdown handler 写 status 失败: '.$t->getMessage());
            }
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
        } catch (\Exception $e) {
            Log::error("[Upgrade] 升级异常: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            $statusManager->fail($e->getMessage());
            $this->error('升级异常: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
