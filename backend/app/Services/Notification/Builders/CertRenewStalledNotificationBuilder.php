<?php

namespace App\Services\Notification\Builders;

use App\Bootstrap\ApiExceptions;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\CertificateProductType;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Order\StalledRenewalQuery;
use DateMalformedStringException;
use DateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * 续期停滞孤儿提醒 Builder（镜像 CertExpireNotificationBuilder）。
 *
 * 续费/重签把前驱证书终态化（renewed/reissued）后，后续证书长期卡在非 active 停滞态、前驱即将到期。
 * cert_expire 对 renewed/reissued 前驱抑制、AutoRenewCommand 因 active 前置不再处理 → 本提醒是唯一止血。
 *
 * 强制发（不入 user_default_preferences）：涉及服务中断风险，穿透用户可能已关的常规到期提醒偏好。
 *
 * 携密不入库：payload 仅域名 / 日期 / 停滞状态标签 / 文案，无 csr/key/token。
 */
class CertRenewStalledNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        if (! $notifiable instanceof User) {
            throw new RuntimeException('通知接收者必须为用户');
        }

        $email = ($intent->context['email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('邮箱为空');
        }

        $siteUrl = get_system_setting('site', 'url', '/');
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');

        $predecessors = $this->fetchStalledPairs($notifiable);

        $certificates = [];

        foreach ($predecessors as $predecessor) {
            // 后续证书（nextCert）：查询形态保证存在，异步重查间若后续证书恰好转出停滞态（转 active＝孤儿消解，
            // 或被取消清理＝断链 null）则跳过——语义正确（不再停滞），非漂移。forUser 主查询已按 5 态 whereIn
            // 过滤，此处对预载 nextCert 再判一次停滞态白名单（复用同一真相源常量），兜住「主查询通过后、nextCert
            // 预载前」的毫秒级 race。expires_at 缺失（理论上不达，renewed/reissued 前驱恒有）时亦跳过。
            $successor = $predecessor->nextCert;
            if (! $successor
                || ! in_array($successor->status, StalledRenewalQuery::SUCCESSOR_STALLED_STATUSES, true)
                || ! $predecessor->expires_at) {
                continue;
            }

            try {
                $daysLeft = (int) (new DateTime)->diff(new DateTime((string) $predecessor->expires_at))->format('%a');
            } catch (DateMalformedStringException $e) {
                app(ApiExceptions::class)->logException($e);
                $daysLeft = 0;
            }

            $predecessorOrder = $predecessor->order;
            $predecessorProduct = $predecessorOrder instanceof Order
                ? $predecessorOrder->getAttribute('product')
                : null;
            $productType = CertificateProductType::normalize(
                $predecessorProduct instanceof Product ? $predecessorProduct->product_type : null
            );
            $certificates[] = [
                'domain' => $predecessor->common_name,
                'expire_at' => $predecessor->expires_at->format('Y-m-d'),
                'days_left' => $daysLeft,
                'stall_status' => $successor->status,
                'action_hint' => $this->actionHint($successor->status, $productType),
                'product_type' => $productType,
                'product_type_label' => CertificateProductType::label($productType),
            ];
        }

        if (empty($certificates)) {
            return null;
        }

        $subject = '证书续期停滞提醒 ['.$siteName.']';
        $data = [
            'username' => $notifiable->username,
            'email' => $email,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'certificates' => $certificates,
            'has_ssl_certificate' => collect($certificates)->contains(
                fn (array $certificate): bool => $certificate['product_type'] === Product::TYPE_SSL
            ),
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }

    /**
     * 拉取该用户「续期停滞」的前驱证书（预载后续证书 nextCert）。
     *
     * 经 StalledRenewalQuery::forUser 单一形态重查（前驱轴 + EXISTS 后续证书 5 态 + 48h 门槛 + 连续 14 天超集
     * 窗口），与 ExpireCommand 派发侧同源，杜绝两侧口径漂移致「派发了 user、Builder 重查为空 → 静默漏发」。
     * 抽为可覆盖方法以便 Unit 测试 mock（同既有 CertExpire fetchExpiringOrders 范式）。
     *
     * @return Collection<int, Cert>
     */
    protected function fetchStalledPairs(User $user): Collection
    {
        return app(StalledRenewalQuery::class)
            ->forUser($user)
            ->with(['nextCert', 'order.product'])
            ->get();
    }

    /**
     * 按后续证书停滞状态计算用户可行动文案（模板只渲染不做逻辑）。
     *
     * unpaid 中性化（未扣费、不硬承诺去支付，避免与 O4 自动清理冲突）；pending/processing/approving 已扣费
     * （勿重复支付）；failed 指「重新购买」——failed/renewed/reissued 三态均进不了 renew/reissue gate，
     * 「重新发起续期」入口落空，唯一真实动作是另开新单。
     */
    private function actionHint(string $successorStatus, string $productType): string
    {
        return match ($successorStatus) {
            'unpaid' => '续期订单尚未完成支付。请登录控制台检查订单状态——可重新支付以继续签发，或取消该订单；长期未处理的订单可能被系统自动清理。',
            'pending' => '续期订单已受理、正在处理中（费用已扣除）。请勿重复下单或重复支付；如长时间未完成请联系客服。',
            'processing', 'approving' => $productType === Product::TYPE_SSL
                ? '新证书正在进行域名验证/审核（费用已扣除）。请尽快完成域名验证或补齐审核材料，以免原证书到期造成服务中断；请勿重复下单或支付。'
                : '新证书正在进行身份验证或签名材料审核（费用已扣除）。请尽快补齐所需材料，以免原证书到期造成业务中断；请勿重复下单或支付。',
            'failed' => '续期订单未能完成签发。请重新购买证书，或联系客服核实订单与费用状态。',
            default => '续期流程未正常完成，请登录控制台检查订单状态，或联系客服。',
        };
    }
}
