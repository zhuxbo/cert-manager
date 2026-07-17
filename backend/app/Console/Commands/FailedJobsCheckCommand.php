<?php

namespace App\Console\Commands;

use App\Services\Notification\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * E5 failed_jobs 阈值监控（P2，07-07 补漏）。
 *
 * 调度：schedule:failed-jobs-check，每天 05:30（freeze 期 skip）；配套 queue:prune-failed
 * weekly 清理（在 console.php 注册）。
 *
 * 窗口增量计数（非整表累计）：仅统计近 window_hours 内新增的 failed_jobs——陈旧事故
 * （最长驻留至 prune）不再驱动每日告警，窗口滑过即自愈。超阈发一封（固定指纹
 * 'threshold_exceeded' 防计数逐日波动 churn，持续超阈每 TTL 至多一封），窗口计数回落 ≤ 阈值
 * 即 clearDedupe（事故结束后下次事故立即告警）。
 *
 * 不改 HealthController 503：① /api/health 供 bt-install 等待/升级 smoke test 消费，堆积致
 * 503 会卡死安装升级；② 积压信号≠存活信号；③ HealthController 属 P0-4 禁区。
 */
class FailedJobsCheckCommand extends Command
{
    protected $signature = 'schedule:failed-jobs-check';

    protected $description = 'failed_jobs 24h 窗口增量计数超阈告警（窗口滑过自愈，不改 HealthController）';

    private const DEDUPE_KEY = 'failed_jobs';

    public function handle(): int
    {
        if (! config('monitoring.failed_jobs.enabled', true)) {
            return self::SUCCESS;
        }

        if (! Schema::hasTable('failed_jobs')) {
            return self::SUCCESS;
        }

        $windowHours = (int) config('monitoring.failed_jobs.window_hours', 24);
        $threshold = (int) config('monitoring.failed_jobs.alert_threshold', 50);

        $count = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHours($windowHours))
            ->count();

        if ($count > $threshold) {
            Log::warning('[failed_jobs] 窗口内失败任务超阈', [
                'window_hours' => $windowHours,
                'count' => $count,
                'threshold' => $threshold,
            ]);
            app(SystemAlert::class)->send(
                'failed_jobs',
                "failed_jobs 近 {$windowHours}h 新增超阈",
                "近 {$windowHours} 小时新增 failed_jobs {$count} 条，超过阈值 {$threshold}，请排查队列异常",
                ['window_hours' => $windowHours, 'count' => $count, 'threshold' => $threshold],
                self::DEDUPE_KEY,
                (int) config('monitoring.failed_jobs.dedupe_ttl_hours', 72),
                'threshold_exceeded', // 固定指纹：计数逐日波动不 churn
            );

            return self::SUCCESS;
        }

        // 窗口计数 ≤ 阈值：清去重键（事故结束后下次事故立即告警）
        app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);

        return self::SUCCESS;
    }
}
