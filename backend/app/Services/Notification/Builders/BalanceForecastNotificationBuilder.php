<?php

namespace App\Services\Notification\Builders;

use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;

/**
 * 余额前瞻预警通知 Builder（A1）。
 *
 * available / required / shortfall / certificates 为业务输入（BalanceForecastCommand 注入）；
 * site_url 由系统设置 site.url 注入（与 auto_renew_failed / cert_expire 一致），不进模板 variables。
 *
 * 语义固化：required 是「预估上限」——包含委托将失败等运行日会被跳过不扣费的单，故文案一律
 * 「预计最多需要 ¥X」，避免用户把上限当精确账单。金额/域名/邮箱均非 secret，走普通 NotificationPayload。
 */
class BalanceForecastNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        $context = $intent->context;

        $data = [
            'available' => $context['available'] ?? '0.00',
            'required' => $context['required'] ?? '0.00',
            'shortfall' => $context['shortfall'] ?? '0.00',
            'certificates' => $context['certificates'] ?? [],
            // 系统设置注入；显式传入时以传入为准（便于测试覆盖）
            'site_url' => $context['site_url'] ?? get_system_setting('site', 'url', '/'),
            'email' => ($context['email'] ?? '') ?: ($notifiable instanceof User ? (string) $notifiable->email : ''),
        ];

        return new NotificationPayload($data);
    }
}
