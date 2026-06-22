<?php

namespace App\Services\Notification\Builders;

use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;

/**
 * 自动续费/重签失败通知 Builder。
 *
 * common_name / action / reason 为业务输入（AutoRenewCommand 注入 / Admin 测试发送填写）；
 * site_url 由系统设置 site.url 注入（与 cert_expire / cert_issued / user_created 一致），
 * 不进模板 variables、不需调用方或测试发送手填——确保「登录控制台」按钮地址始终取自系统设置。
 */
class AutoRenewFailedNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        $context = $intent->context;

        $data = [
            'common_name' => $context['common_name'] ?? '',
            'action' => $context['action'] ?? '',
            'reason' => $context['reason'] ?? '',
            // 系统设置注入；显式传入时以传入为准（便于测试覆盖）
            'site_url' => $context['site_url'] ?? get_system_setting('site', 'url', '/'),
            'email' => ($context['email'] ?? '') ?: ($notifiable instanceof User ? (string) $notifiable->email : ''),
        ];

        return new NotificationPayload($data);
    }
}
