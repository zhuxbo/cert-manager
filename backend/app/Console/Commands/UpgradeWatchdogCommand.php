<?php

namespace App\Console\Commands;

use App\Services\Notification\SystemAlert;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Utils\UpgradeFreezeLock;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H1 升级看门狗：升级进程被硬杀（SIGKILL/OOM）后自愈，防 status.json 卡 running +
 * 维护模式 / 冻结无人解除。
 *
 * 判定分四支（PID 存活是「不动作」的一票否决）：
 *  - running 且 time-stale 且 进程死 且 冻结锁属于该死升级（或无锁）
 *    → fail + artisan up + unfreeze + 去重 SystemAlert；
 *  - running 且 time-stale 且 进程死 但 冻结锁属他方（upgrade.sh 覆盖式升级 / admin 手动 freeze）
 *    → 零动作仅去重告警：stale 只证明「status.json 追踪的那场 web 升级已死」，不证明当前锁属于它；
 *      此刻 unfreeze+up 会拆掉他方升级危险窗（migrate/seed）唯一的 HTTP 写闸（本仓已删
 *      PreventRequestsDuringMaintenance，freeze 是唯一挡外部写的闸）。他方解锁后下一分钟照常自愈；
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

    private const FOREIGN_DEDUPE_KEY = 'upgrade_watchdog_foreign';

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

        // stale = 超时 且 进程死 → 自愈（先过冻结锁归属关）
        $pid = $data['pid'] ?? null;
        $staleSeconds = (int) config('upgrade.stale_seconds', 3600);

        // 冻结锁归属校验：他方持锁（upgrade.sh 经 artisan upgrade:freeze 的 shell 锁 /
        // admin 手动 manual 锁 / 身份不符的 web 锁）→ 零动作，防拆他方升级危险窗的 HTTP 写闸。
        // 不 fail：留 status 原样，他方解锁后下一分钟走正常自愈收敛。
        $lock = UpgradeFreezeLock::info();
        if ($lock !== null && ! $this->lockBelongsToTrackedUpgrade($lock, $data)) {
            Log::warning('[upgrade_watchdog] 冻结锁归属他方（疑似 upgrade.sh 升级进行中），跳过自愈', [
                'lock_owner_source' => $lock['owner_source'] ?? 'legacy',
                'lock_frozen_at' => $lock['frozen_at'] ?? null,
                'status_pid' => $pid,
                'version' => $data['version'] ?? null,
            ]);

            app(SystemAlert::class)->send(
                'upgrade',
                '升级看门狗跳过自愈',
                '存在中断升级残留，但冻结锁属他方升级流程，已跳过解除维护（对方解锁后自动恢复自愈）',
                [
                    'version' => (string) ($data['version'] ?? ''),
                    'pid' => (string) ($pid ?? ''),
                    'lock_owner' => (string) ($lock['owner_source'] ?? 'legacy'),
                ],
                self::FOREIGN_DEDUPE_KEY,
                24,
            );

            return self::SUCCESS;
        }
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

    /**
     * 冻结锁是否属于 status.json 追踪的那场（已死的）web 升级。
     *
     * - 新格式锁（有 owner_source）：仅 owner_source=web 且 owner_pid 与 status.pid 同进程才算本升级
     *   （shell 锁的 owner 是 artisan 子进程、写完即退，天然 ≠ status.pid；manual 锁不归 watchdog 清，
     *   TTL 兜底）。
     * - 旧格式锁（无 owner 字段，含 N-1 版 artisan 在 upgrade.sh 切码前写的锁）：回退时间判定——
     *   frozen_at ≤ 死升级最后心跳 + 60s 判本升级（web 路径 freeze 后紧跟 startStep('apply') 心跳，
     *   间隔毫秒级；60s 容差覆盖 freeze 后立刻被 SIGKILL 的窄窗）。frozen_at 晚于心跳 60s 以上 =
     *   死进程不可能写下它 → 他方（stale 前置保证心跳至少 stale_seconds 旧，两侧分离度充足）。
     * - 时间缺失/不可解析：回落「本升级」维持既有自愈——缺 frozen_at 的锁永不过期（isExpired 兼容
     *   分支），watchdog 是它唯一的清道夫。
     *
     * @param  array<string, mixed>  $lock
     * @param  array<string, mixed>  $status
     */
    private function lockBelongsToTrackedUpgrade(array $lock, array $status): bool
    {
        if (array_key_exists('owner_source', $lock)) {
            if (($lock['owner_source'] ?? null) !== 'web') {
                return false;
            }

            $ownerPid = $lock['owner_pid'] ?? null;
            $statusPid = $status['pid'] ?? null;

            return is_numeric($ownerPid) && is_numeric($statusPid)
                && (int) $ownerPid === (int) $statusPid;
        }

        $frozenAt = $lock['frozen_at'] ?? null;
        $heartbeat = $status['updated_at'] ?? $status['started_at'] ?? null;
        if (! is_string($frozenAt) || $frozenAt === '' || ! is_string($heartbeat) || $heartbeat === '') {
            return true;
        }

        try {
            return Carbon::parse($frozenAt)->lessThanOrEqualTo(Carbon::parse($heartbeat)->addSeconds(60));
        } catch (Throwable) {
            return true;
        }
    }
}
