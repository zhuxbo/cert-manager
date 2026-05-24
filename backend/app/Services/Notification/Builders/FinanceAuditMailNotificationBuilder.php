<?php

namespace App\Services\Notification\Builders;

use App\Models\Admin;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class FinanceAuditMailNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        if (! $notifiable instanceof Admin) {
            throw new RuntimeException('通知接收者必须为管理员');
        }

        $email = ($intent->context['admin_email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('管理员邮箱为空');
        }

        $subject = get_system_setting('site', 'name', 'SSL证书管理系统').' 资金审计告警';

        $data = [
            'admin_email' => $email,
            'email' => $email,
            'violation_count' => (int) ($intent->context['violation_count'] ?? 0),
            'violations' => $intent->context['violations'] ?? [],
            'detected_at' => $intent->context['detected_at'] ?? now()->toDateTimeString(),
            'subject' => $subject,
            '_meta' => [
                'email' => $email,
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data, ['mail']);
    }
}
