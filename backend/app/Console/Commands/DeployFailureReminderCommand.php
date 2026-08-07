<?php

namespace App\Console\Commands;

use App\Models\AutoDeployReport;
use App\Services\Notification\SystemAlert;
use Illuminate\Console\Command;

/**
 * 自动部署 / 签发失败小时聚合告警。
 *
 * 失败事件只逐条写 auto_deploy_reports，不在请求/续签链路即时发信。本命令每小时汇总上一个完整小时的
 * failure 行，跨订单只发一封 SystemAlert；小时指纹防同一窗口重跑重复发送。
 */
class DeployFailureReminderCommand extends Command
{
    protected $signature = 'schedule:deploy-failure-reminder';

    protected $description = '按完整小时聚合自动部署/签发失败并发送一封管理员告警';

    public function handle(): void
    {
        $windowEnd = now()->startOfHour();
        $windowStart = $windowEnd->copy()->subHour();
        $failures = AutoDeployReport::query()
            ->where('status', 'failure')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd);
        $reportCount = (clone $failures)->count();

        if ($reportCount === 0) {
            $this->info("上一小时无失败（{$windowStart->toDateTimeString()} - {$windowEnd->toDateTimeString()}）");

            return;
        }

        $orderCount = (clone $failures)->distinct()->count('order_id');
        $orderSample = (clone $failures)
            ->select('order_id')
            ->distinct()
            ->orderBy('order_id')
            ->limit(20)
            ->pluck('order_id')
            ->implode(',');
        $clientFailureCount = (clone $failures)->whereNotNull('ip')->count();
        app(SystemAlert::class)->send(
            'deploy_failure',
            "自动部署/签发失败小时汇总：{$reportCount} 条",
            "{$windowStart->format('Y-m-d H:i')} 至 {$windowEnd->format('H:i')} 共 {$reportCount} 条失败，涉及 {$orderCount} 个订单，请在自动部署记录中查看详情。",
            [
                'report_count' => $reportCount,
                'order_count' => $orderCount,
                'client_failure_count' => $clientFailureCount,
                'server_failure_count' => $reportCount - $clientFailureCount,
                'order_sample' => $orderSample,
                'window_start' => $windowStart->toDateTimeString(),
                'window_end' => $windowEnd->toDateTimeString(),
            ],
            'deploy_failure_hourly',
            48,
            'deploy_failure_'.$windowStart->format('YmdH')
        );

        $this->info("自动部署/签发失败小时汇总：{$reportCount} 条，{$orderCount} 个订单");
    }
}
