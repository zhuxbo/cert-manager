<?php

namespace App\Console\Commands;

use App\Services\Notification\SystemAlert;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * H1 升级看门狗：升级进程被硬杀（SIGKILL/OOM）后自愈，防 status.json 卡 running +
 * 维护模式 / 冻结无人解除。
 *
 * 判定分三支（PID 存活是「不动作」的一票否决）：
 *  - running 且 time-stale 且 进程死  → 升级进程已死 → fail + artisan up + unfreeze + 去重 SystemAlert；
 *  - running 且 time-stale 但 进程活  → 慢单步（大库 migrate / 慢镜像 composer）→ 仅 Log::warning，不动作
 *    （误 up 会把半迁移库 + 半换代码放给流量、并唤醒被 down 暂停的 worker 去 pop 半迁移库上的 job，比卡死更坏）；
 *  - 其余（无 status / completed / failed / 心跳新鲜）→ no-op。
 *
 * 调度：Schedule::command('upgrade:watchdog')->everyMinute()->evenInMaintenanceMode()，
 * 且**不挂** skip($skipWhenFrozen)——自愈命令必须在冻结期存活（见 console.php 注释）。
 * fail/up/unfreeze 皆幂等，重复触发安全；下一分钟 status 已 failed → no-op。
 */
class UpgradeWatchdogCommand extends Command
{
    protected $signature = 'upgrade:watchdog';

    protected $description = '升级看门狗：升级进程死后自动解除维护/冻结（PID 存活一票否决慢单步误判）';

    private const DEDUPE_KEY = 'upgrade_watchdog';

    public function handle(UpgradeStatusManager $statusManager): int
    {
        $data = $statusManager->get();

        // 无 status / 非 running（completed/failed）→ no-op（正常成功升级不会被误解维护）
        if (! $data || ($data['status'] ?? null) !== 'running') {
            return self::SUCCESS;
        }

        // 步骤超时但进程仍存活 → 慢单步，绝不动作（PID 一票否决）
        if (! $statusManager->isStale($data)) {
            if ($statusManager->isTimeStale($data)) {
                Log::warning('[upgrade_watchdog] 升级步骤超时但进程仍存活，暂不解除维护', [
                    'pid' => $data['pid'] ?? null,
                    'version' => $data['version'] ?? null,
                ]);
            }

            return self::SUCCESS;
        }

        // stale = 超时 且 进程死 → 自愈
        $pid = $data['pid'] ?? null;
        $staleSeconds = (int) config('upgrade.stale_seconds', 3600);
        $message = sprintf(
            '升级进程已死（PID %s 不存活，超 %ds 无心跳），已自动解除维护',
            $pid ?? 'unknown',
            $staleSeconds,
        );
        Log::error('[upgrade_watchdog] '.$message, ['version' => $data['version'] ?? null]);

        // 先解冻再 up：与升级路径同序（up 唤醒 worker，freeze 仍在则 release 烧 attempts）
        $statusManager->fail($message);
        UpgradeFreezeLock::unfreeze();
        Artisan::call('up');

        // 去重告警（每分钟触发但同一卡死态只发一封）——details 不含敏感串
        app(SystemAlert::class)->send(
            'upgrade',
            '升级看门狗自愈',
            '升级进程中断已自动解除维护',
            [
                'version' => (string) ($data['version'] ?? ''),
                'pid' => (string) ($pid ?? ''),
                'stale_seconds' => $staleSeconds,
            ],
            self::DEDUPE_KEY,
            24,
        );

        return self::SUCCESS;
    }
}
