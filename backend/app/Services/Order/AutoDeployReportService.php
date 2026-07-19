<?php

namespace App\Services\Order;

use App\Models\AutoDeployReport;
use App\Models\Order;
use App\Services\Notification\SystemAlert;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自动部署上报记录与失败告警消噪。
 *
 * 记录全量、通知消噪：
 *  - 记录层零抑制——每次失败（客户端部署回调或服务端自写签发失败）照常入 auto_deploy_reports；
 *  - 通知层去噪——任一 status=failure 行（不分签发/部署来源）触发一封 SystemAlert，按订单固定指纹
 *    去重，TTL 内同一订单重复失败只入表不再通知，TTL 到期仍未解决再提醒一封（持续未解决提醒由
 *    DeployFailureReminderCommand 基于状态判定驱动，覆盖客户端触顶静默期）。
 *
 * 服务端自写签发失败行的两个写入点：local CSR 提交后的服务端处理失败（Deploy\ApiController::update）、
 * pull scheduler 自动重签失败（AutoRenewCommand），均 order_id 归因 + cert_id 取订单唯一 latestCert、
 * ip 留空，客户端零参与；message 以明确失败原因开头，与客户端部署失败 message 天然可辨。
 *
 * 恢复侧对称：客户端部署成功回调清去重键；同订单自动重签成功且失败在案时，服务端自写一行恢复
 * 记录并清键（recordServerRecovery），使 DeployFailureReminderCommand 的状态判定收敛——否则无客户端
 * 的 web 订单重签恢复后「最后一条报告」永远停在 failure，被按 TTL 持续误提醒。
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

    private const ALERT_CATEGORY = 'deploy_failure';

    /**
     * 固定指纹：同一订单持续失败在 TTL 内只发一封（内容/来源变化不翻新指纹，防 churn 击穿 per-order 去重）。
     */
    private const ALERT_FINGERPRINT = 'deploy_failure';

    public function __construct(private readonly SystemAlert $systemAlert) {}

    /**
     * 服务端自写一行 status=failure 记录（ip 留空）+ 触发失败告警。
     *
     * 用于 local CSR 提交后的服务端处理失败、pull scheduler 自动重签失败两个写入点，客户端零参与。
     * 上报为尽力而为：留痕/告警失败不得中断续签/提交主流程。
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

        $this->notifyFailure($order, $message);
    }

    /**
     * 任一 status=failure（客户端回调或服务端自写）触发一封 SystemAlert，按订单固定指纹去重。
     *
     * TTL 内同一订单重复失败只入表不再通知；TTL 到期仍失败（新失败行或持续未解决提醒）再提醒一封。
     *
     * @return bool 是否实际派发（false = 去重跳过 / 无 admin / dispatch 失败）
     */
    public function notifyFailure(Order $order, ?string $message = null): bool
    {
        try {
            return $this->systemAlert->send(
                self::ALERT_CATEGORY,
                "订单 #{$order->id} 自动部署/签发失败",
                $message !== null && $message !== ''
                    ? $this->sanitizeMessage($message)
                    : '该订单最近一次自动部署或签发失败且尚未恢复，请查看自动部署上报记录并处理',
                [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                ],
                $this->dedupeKey($order),
                (int) config('deploy.failure_alert.dedupe_ttl_hours', 168),
                self::ALERT_FINGERPRINT,
            );
        } catch (Throwable $e) {
            Log::warning('[auto_deploy_report] 失败告警派发异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 同订单自动重签成功后的服务端恢复行（ip 留空）+ 清失败告警去重键。
     *
     * 仅当最后一条在案报告为 failure 时写入（无失败在案不写，避免全量成功噪音），使
     * DeployFailureReminderCommand 的「最后一条仍 failure」状态判定收敛。renew 成功建新单、
     * 旧订单终态天然免疫；部署客户端在场时由其 success 回调恢复——本方法专堵「同订单 reissue
     * 成功 + 无客户端回调」形态。尽力而为：留痕/清键失败不得中断续签主流程。
     *
     * 来源门：重签成功只解决签发侧失败，不代表部署恢复。最近一条客户端上报行（ip 非空）仍为
     * failure 时（含最后一条本身就是客户端部署失败），部署问题只能由客户端 success 回调解除——
     * 不写恢复行、不清键，保留 reminder 持续提醒（触顶静默客户端正是靠这条兜底）。
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

        $this->clearFailureAlert($order);
    }

    /**
     * 部署成功即清去重键：问题已解决，复发时立即再告警（healthy 分支清键，防旧键把复发静默压掉）。
     */
    public function clearFailureAlert(Order $order): void
    {
        try {
            $this->systemAlert->clearDedupe($this->dedupeKey($order));
        } catch (Throwable $e) {
            Log::warning('[auto_deploy_report] 失败告警清键异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * per-order 去重键：事件驱动（回调/自写）与持续未解决提醒共用同一键，
     * 由 SystemAlert 的固定指纹 + TTL 统一裁决「TTL 内一封、到期再一封」。
     */
    private function dedupeKey(Order $order): string
    {
        return "deploy_failure_{$order->id}";
    }

    private function sanitizeMessage(string $message): string
    {
        return mb_substr(strip_tags($message), 0, 500);
    }
}
