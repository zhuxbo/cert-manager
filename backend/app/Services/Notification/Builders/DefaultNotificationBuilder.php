<?php

namespace App\Services\Notification\Builders;

use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;

/**
 * 兜底 Builder：当 config('notification.builders') 中未匹配到 code 时使用。
 *
 * ⚠️ 安全提示：本 Builder 直通 $intent->context 作为 payload.data，未做敏感字段过滤。
 *   调用方（Admin 测试通知 / 插件自定义 code）必须自负责任 — context 中不要传递
 *   password / token / secret / api_key / private_key 等敏感字段，因为 data 会被持久化
 *   到 notifications.data 列（明文存储），且可能被 channel 转发到外部服务（邮件、IM 等）。
 *
 *   主系统多个内置 code（见 config/notification.builders，如 cert_issued / cert_expire /
 *   task_failed / finance_audit / security / user_created / auto_renew_failed）均显式
 *   注册了 Builder，由各自 Builder 控制 data 结构，不走此兜底路径。
 */
class DefaultNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        return new NotificationPayload($intent->context);
    }
}
