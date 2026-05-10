<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Utils\UpgradeFreezeLock;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * 升级 unfreeze 命令：删除 storage/framework/upgrade.lock
 *
 * 由 upgrade.sh / 后台覆盖式升级流程在新版本 smoke test 通过后调用：
 * 1. 删除 storage/framework/upgrade.lock
 * 2. MaintenanceMode 中间件下次命中即放行
 * 3. LogOperation 恢复正常写日志
 *
 * 与 POST /api/admin/upgrade/unfreeze 等价。
 *
 * 锁文件不存在时静默成功（双重 unfreeze 也不报错），与 UpgradeFreezeLock::unfreeze() 行为一致。
 */
class UpgradeUnfreezeCommand extends Command
{
    protected $signature = 'upgrade:unfreeze';

    protected $description = '删除升级冻结锁，解除 HTTP 维护态';

    public function handle(): int
    {
        UpgradeFreezeLock::unfreeze();

        if (UpgradeFreezeLock::isFrozen()) {
            $this->error('删除 upgrade.lock 失败，请检查 storage/framework 目录权限');

            return CommandAlias::FAILURE;
        }

        $this->info('升级冻结锁已删除，HTTP 维护态解除');

        return CommandAlias::SUCCESS;
    }
}
