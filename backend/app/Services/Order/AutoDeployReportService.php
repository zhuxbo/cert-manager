<?php

namespace App\Services\Order;

use App\Models\AutoDeployReport;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自动部署上报记录服务。
 *
 * 每次失败（客户端部署回调或服务端自写签发失败）照常入 auto_deploy_reports；管理员通知由
 * DeployFailureReminderCommand 按上一个完整小时跨订单聚合，不在业务请求中即时派发。
 *
 * 服务端自写签发失败行的两个写入点：local CSR 提交后的服务端处理失败（Deploy\ApiController::update）、
 * pull scheduler 自动重签失败（AutoRenewCommand），均 order_id 归因 + cert_id 取订单唯一 latestCert、
 * ip 留空，客户端零参与；message 以明确失败原因开头，与客户端部署失败 message 天然可辨。
 *
 * 同订单自动重签成功且签发失败在案时，服务端自写一行恢复记录，保持报告状态链完整。
 */
class AutoDeployReportService
{
    /**
     * 订单终态证书状态集：订单终局或证书已过期后停止告警提醒，报告进入清理集。
     * 与 Order/Acme\Action::sync 终态守卫集一致，另含 expired（ExpireCommand 到期翻转）。
     */
    public const ORDER_TERMINAL_CERT_STATUSES = [
        'cancelled', 'revoked', 'renewed', 'reissued', 'expired', 'failed',
    ];

    /**
     * 服务端自写一行 status=failure 记录（ip 留空）。
     *
     * 用于 local CSR 提交后的服务端处理失败、pull scheduler 自动重签失败两个写入点，客户端零参与。
     * 上报为尽力而为：留痕失败不得中断续签/提交主流程。
     */
    public function recordServerFailure(Order $order, string $message): void
    {
        $cert = $order->latestCert;

        // 签发失败时 latestCert 就是正在续签的前驱证书、恒存在；缺失属异常，跳过留痕不阻断主流程。
        if (! $cert) {
            return;
        }

        try {
            AutoDeployReport::create([
                'order_id' => $order->id,
                'cert_id' => $cert->id,
                'status' => 'failure',
                'deployed_at' => null,
                'ip' => null, // 服务端自写：来源 IP 留空，与客户端回调天然可辨
                'message' => $this->sanitizeMessage($message),
            ]);
        } catch (Throwable $e) {
            Log::warning('[auto_deploy_report] 服务端签发失败留痕失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }
    }

    /**
     * 同订单自动重签成功后的服务端恢复行（ip 留空）。
     *
     * 仅当最后一条在案报告为 failure 时写入（无失败在案不写，避免全量成功噪音），使
     * 报告状态链收敛。renew 成功建新单、
     * 旧订单终态天然免疫；部署客户端在场时由其 success 回调恢复——本方法专堵「同订单 reissue
     * 成功 + 无客户端回调」形态。尽力而为：留痕失败不得中断续签主流程。
     *
     * 来源门：重签成功只解决签发侧失败，不代表部署恢复。最近一条客户端上报行（ip 非空）仍为
     * failure 时（含最后一条本身就是客户端部署失败），部署问题只能由客户端 success 回调解除——
     * 不写恢复行，保留客户端部署失败状态。
     */
    public function recordServerRecovery(Order $order, string $message): void
    {
        try {
            $last = AutoDeployReport::query()
                ->where('order_id', $order->id)
                ->orderByDesc('id')
                ->first();

            if (! $last || $last->status !== 'failure') {
                return;
            }

            // 来源门：客户端部署失败未被客户端 success 回调解除 → 恢复不成立，保留失败态与去重键
            $lastClient = AutoDeployReport::query()
                ->where('order_id', $order->id)
                ->whereNotNull('ip')
                ->orderByDesc('id')
                ->first();

            if ($lastClient && $lastClient->status === 'failure') {
                return;
            }

            // 重签成功后 latest_cert_id 已切新证书，调用方内存模型可能仍持旧关系 → 回读取新值
            $freshCertId = Order::query()->whereKey($order->id)->value('latest_cert_id');

            AutoDeployReport::create([
                'order_id' => $order->id,
                'cert_id' => $freshCertId ?? $last->cert_id,
                'status' => 'success',
                'deployed_at' => null,
                'ip' => null, // 服务端自写：来源 IP 留空，与客户端回调天然可辨
                'message' => $this->sanitizeMessage($message),
            ]);
        } catch (Throwable $e) {
            Log::warning('[auto_deploy_report] 服务端恢复留痕失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }
    }

    private function sanitizeMessage(string $message): string
    {
        return mb_substr(strip_tags($message), 0, 500);
    }
}
