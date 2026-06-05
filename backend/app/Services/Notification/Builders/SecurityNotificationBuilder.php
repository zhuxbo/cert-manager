<?php

namespace App\Services\Notification\Builders;

use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * 账号安全变更通知 Builder（登录密码修改 / 重置等）。
 *
 * 用专用 Builder 而非回落 DefaultNotificationBuilder：显式白名单 username/event/email 入库，
 * 避免调用方误把敏感字段（如新密码）塞进 context 后被 Default 直通进 notifications.data。
 * event 仅为安全事件的可读描述（如「登录密码已修改」），不含任何凭据。模板为纯文本，故 is_html=false。
 */
class SecurityNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
    {
        if (! $notifiable instanceof User) {
            throw new RuntimeException('通知接收者必须为用户');
        }

        $email = ($intent->context['email'] ?? '') ?: (string) $notifiable->email;
        if (! $email) {
            throw new RuntimeException('邮箱为空');
        }

        $event = trim((string) ($intent->context['event'] ?? ''));
        if ($event === '') {
            throw new RuntimeException('安全事件描述不能为空');
        }

        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');
        $subject = "账号安全提醒 [{$siteName}]";

        $data = [
            'username' => $notifiable->username,
            'event' => $event,
            'email' => $email,
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => false,
            ],
        ];

        return new NotificationPayload($data);
    }
}
