<?php

namespace App\Services\Notification\Builders;

use App\Models\User;
use App\Services\Notification\CertificateProductType;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * 证书吊销一次性通知 Builder（镜像 CertRenewCancelledNotificationBuilder）。
 *
 * Order sync 发现上游把证书同步为 revoked 终态时触发。吊销是独立于续费的重大服务中断事件：被吊销证书
 * 立即失去 CA 信任、浏览器拦截访问，用户须知悉并按需重新申请。对所有 revoked（含 plain new）派发；若
 * 被吊销的是续费/重签新订单（is_successor=true），前驱证书亦脱离 cert_expire / AutoRenew /
 * cert_renew_stalled 三重监控，模板附带说明。
 *
 * 事件驱动（≠ CertRenewStalled 的周期重查范式）：数据在吊销发生时即确定、终态不再变动，故直接读派发点
 * context 白名单标量，不重查 DB。
 *
 * 强制发（不入 user_default_preferences）：吊销属服务中断类事件，穿透用户可能已关的常规到期偏好。
 *
 * 携密不入库：payload 仅域名 / 日期 / 订单号 / 新订单标志 + 固定文案；白名单构造 $data，绝不整包直通
 * $intent->context（DefaultNotificationBuilder 直通红线）。
 */
class CertRevokedNotificationBuilder implements NotificationBuilderInterface
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

        // 白名单逐键读取 context 标量（派发点已白名单塞入，此处再显式取用，绝不整包直通 $intent->context）
        $commonName = (string) ($intent->context['common_name'] ?? '');
        $expiresAt = (string) ($intent->context['expires_at'] ?? '');
        $orderId = (int) ($intent->context['order_id'] ?? 0);
        $isSuccessor = (bool) ($intent->context['is_successor'] ?? false);
        $productType = CertificateProductType::normalize($intent->context['product_type'] ?? null);
        $productTypeLabel = CertificateProductType::label($productType);

        $siteUrl = get_system_setting('site', 'url', '/');
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');
        $subject = $productTypeLabel.' 证书吊销提醒 ['.$siteName.']';

        $data = [
            'username' => $notifiable->username,
            'email' => $email,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'common_name' => $commonName,
            'expires_at' => $expiresAt,
            'order_id' => $orderId,
            'is_successor' => $isSuccessor,
            'product_type' => $productType,
            'product_type_label' => $productTypeLabel,
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }
}
