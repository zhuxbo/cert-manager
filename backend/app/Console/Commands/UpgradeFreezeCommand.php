<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Utils\UpgradeFreezeLock;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * 升级 freeze 命令：写入 storage/framework/upgrade.lock
 *
 * 由 upgrade.sh / 后台覆盖式升级流程在切代码、跑 migrate 之前调用：
 * 1. 写文件 storage/framework/upgrade.lock
 * 2. MaintenanceMode 中间件下次命中即返回 503（白名单除外）
 * 3. LogOperation 短路不再写日志
 *
 * 与 POST /api/admin/upgrade/freeze 等价，区别仅在调用入口（脚本 vs HTTP）。
 */
class UpgradeFreezeCommand extends Command
{
    protected $signature = 'upgrade:freeze
                            {--from= : 当前版本（可选，仅记录用）}
                            {--to= : 目标版本（可选，仅记录用）}
                            {--ttl=7200 : 锁文件 TTL 秒数，60 ~ 7200}
                            {--source=shell : 锁持有方（web|shell|manual），upgrade:watchdog 据此判归属}';

    protected $description = '写入升级冻结锁，进入 HTTP 维护态';

    public function handle(): int
    {
        $ttlRaw = $this->option('ttl');
        if (! is_numeric($ttlRaw)) {
            $this->error('--ttl 必须是整数');

            return CommandAlias::FAILURE;
        }

        $ttl = (int) $ttlRaw;
        if ($ttl < 60 || $ttl > 7200) {
            $this->error("--ttl 必须在 60 ~ 7200 范围内，当前: $ttl");

            return CommandAlias::FAILURE;
        }

        $from = $this->option('from');
        $to = $this->option('to');

        $source = $this->option('source');
        if (! is_string($source) || ! in_array($source, ['web', 'shell', 'manual'], true)) {
            $this->error('--source 必须是 web|shell|manual 之一');

            return CommandAlias::FAILURE;
        }

        UpgradeFreezeLock::freeze(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            $ttl,
            $source,
        );

        if (! UpgradeFreezeLock::isFrozen()) {
            $this->error('写入 upgrade.lock 失败，请检查 storage/framework 目录权限');

            return CommandAlias::FAILURE;
        }

        $info = UpgradeFreezeLock::info();
        $this->info('升级冻结锁已写入');
        if ($info !== null) {
            $this->line('  frozen_at  : '.($info['frozen_at'] ?? 'unknown'));
            $this->line('  version_from: '.($info['version_from'] ?? 'null'));
            $this->line('  version_to  : '.($info['version_to'] ?? 'null'));
            $this->line('  ttl_seconds : '.($info['ttl_seconds'] ?? 'unknown'));
            $this->line('  owner       : '.($info['owner_source'] ?? 'unknown').' pid='.($info['owner_pid'] ?? 'unknown'));
        }
        $this->line('  path        : '.UpgradeFreezeLock::path());

        return CommandAlias::SUCCESS;
    }
}
