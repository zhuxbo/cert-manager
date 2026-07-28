<?php

namespace App\Console\Commands;

use App\Models\AutoDeployReport;
use App\Models\Order;
use App\Services\Order\AutoDeployReportService;
use Illuminate\Console\Command;

/**
 * 自动部署 / 签发持续未解决失败提醒。
 *
 * 事件驱动告警（回调失败 / 服务端自写签发失败）在失败发生时发一封并按订单去重；但客户端触顶后进入静默、
 * 不再上报新失败行，问题却仍在。本命令基于「订单最后一条上报仍为 failure 且无后续 success」的状态判定
 * 周期扫描，复用同一 per-order 去重键 + 固定指纹调用 SystemAlert——由 TTL 统一裁决「TTL 内一封、到期仍
 * 未解决再提醒一封」，覆盖触顶静默期使问题不被遗忘；证书已过期或订单终态后停止提醒，交管理端人工视图。
 */
class DeployFailureReminderCommand extends Command
{
    protected $signature = 'schedule:deploy-failure-reminder';

    protected $description = '自动部署/签发持续未解决失败提醒（订单最后一条上报仍为 failure 时按 TTL 再提醒）';

    public function handle(): void
    {
        $service = app(AutoDeployReportService::class);

        // 「最后一条报告仍为 failure 且无后续 success」= 每个订单最新一行（MAX(id)，Snowflake ID 时序单调）为
        // failure。基于状态而非新失败行判定，覆盖客户端触顶后不再上报的静默期。
        $failingOrderIds = AutoDeployReport::query()
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('auto_deploy_reports')
                    ->groupBy('order_id');
            })
            ->where('status', 'failure')
            ->pluck('order_id');

        if ($failingOrderIds->isEmpty()) {
            $this->info('无未解决的自动部署/签发失败订单');

            return;
        }

        $terminal = AutoDeployReportService::ORDER_TERMINAL_CERT_STATUSES;
        $reminded = 0;

        foreach ($failingOrderIds->chunk(200) as $idChunk) {
            Order::with('latestCert')
                ->whereIn('id', $idChunk)
                ->get()
                ->each(function (Order $order) use ($service, $terminal, &$reminded) {
                    $cert = $order->latestCert;
                    if (! $cert) {
                        return;
                    }

                    // 证书已过期或订单终态后停止提醒（含 expires_at 已过但尚未被 ExpireCommand 翻 expired 的时序缝）
                    if (in_array($cert->status, $terminal, true)
                        || ($cert->expires_at && $cert->expires_at->isPast())) {
                        return;
                    }

                    // 复用事件驱动同一 per-order 去重键 + 固定指纹：TTL 内被去重跳过、到期仍未解决才实际再发一封
                    if ($service->notifyFailure($order)) {
                        $reminded++;
                    }
                });
        }

        $this->info("自动部署/签发持续失败提醒：本轮再提醒 $reminded 个订单");
    }
}
