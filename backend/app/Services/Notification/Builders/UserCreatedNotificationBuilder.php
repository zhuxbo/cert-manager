<?php

namespace App\Services\Notification\Builders;

use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;

/**
 * 用户创建通知 Builder。
 *
 * 初始密码必须出现在发给用户的欢迎邮件正文（模板含 {{ $password }}），但绝不能
 * 落入 notifications.data 永久明文存储。故把 password 放进 NotificationPayload 的
 * transient（仅渲染期注入、发送后还原、不持久化），其余字段正常入库。
 *
 * 这样既保留「邮件交付初始凭据」的既有行为，又消除了明文密码长期留存在
 * notifications.data 列的风险（DefaultNotificationBuilder 会直通 context 入库）。
 */
class UserCreatedNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        $context = $intent->context;

        $email = ($context['email'] ?? '') ?: ($notifiable instanceof User ? (string) $notifiable->email : '');

        // 持久化数据：不含明文密码
        $data = [
            'username' => $context['username'] ?? ($notifiable instanceof User ? $notifiable->username : ''),
            'site_name' => $context['site_name'] ?? get_system_setting('site', 'name', 'SSL证书管理系统'),
            'site_url' => $context['site_url'] ?? get_system_setting('site', 'url', '/'),
            'email' => $email,
        ];

        // 仅渲染期需要、绝不入库的敏感字段
        $transient = [
            'password' => (string) ($context['password'] ?? ''),
        ];

        return new NotificationPayload($data, $transient);
    }
}
