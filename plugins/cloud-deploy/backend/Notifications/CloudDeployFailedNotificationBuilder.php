<?php

namespace Plugins\CloudDeploy\Notifications;

use App\Services\Notification\Builders\NotificationBuilderInterface;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;

/**
 * 云部署失败通知 Builder。专用而非回落 Default：白名单硬编码 4 字段入库，
 * 即便调用方误把 error/last_error（含 AK 的厂商报文）塞进 context 也进不了
 * notifications.data —— 代码层堵死，不靠“调用方自律”。纯文本邮件（is_html=false）。
 */
class CloudDeployFailedNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        $ctx = $intent->context;
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');
        $subject = "云部署失败提醒 [{$siteName}]";

        return new NotificationPayload([
            'product' => (string) ($ctx['product'] ?? '-'),
            'domain' => (string) ($ctx['domain'] ?? '-'),
            'access_name' => (string) ($ctx['access_name'] ?? '-'),
            'error_code' => (string) ($ctx['error_code'] ?? '-'),
            'subject' => $subject,
            '_meta' => ['subject' => $subject, 'is_html' => false],
        ]);
    }
}
