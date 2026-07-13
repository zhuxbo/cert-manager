<?php

namespace App\Services\Notification\Builders;

use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * 接替单取消一次性通知 Builder（镜像 CertRenewStalledNotificationBuilder / DelegationInvalidNotificationBuilder）。
 *
 * 续费/重签接替单在 processing/approving（已提交上游 CA，含已签发 active）状态被取消后，前驱证书
 * （renewed/reissued 终态）脱离 cert_expire / AutoRenew / cert_renew_stalled 三重监控——原证书物理上
 * 仍在有效期内服役，却不再收到任何到期/续期提醒。本一次性通知是唯一止血：告知用户接替单已取消、
 * 原证书不再受续期监控，如需继续使用请手动续期。
 *
 * 事件驱动（≠ CertRenewStalled 的周期重查范式）：数据在取消发生时即确定、前驱处终态不再变动，故直接
 * 读派发点 context 白名单标量，不重查 DB。
 *
 * 强制发（不入 user_default_preferences）：涉及服务连续性风险，穿透用户可能已关的常规到期提醒偏好。
 *
 * 携密不入库：payload 仅域名 / 日期 / 订单号 / 动作类型 + 固定文案；白名单构造 $data，绝不整包
 * 直通 $intent->context（DefaultNotificationBuilder 直通红线）。
 */
class CertRenewCancelledNotificationBuilder implements NotificationBuilderInterface
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
        $action = (string) ($intent->context['action'] ?? '续期');

        $siteUrl = get_system_setting('site', 'url', '/');
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');
        $subject = '证书接替单取消提醒 ['.$siteName.']';

        $data = [
            'username' => $notifiable->username,
            'email' => $email,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'common_name' => $commonName,
            'expires_at' => $expiresAt,
            'order_id' => $orderId,
            'action' => $action,
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }
}
