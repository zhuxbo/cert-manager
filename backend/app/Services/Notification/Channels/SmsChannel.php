<?php

namespace App\Services\Notification\Channels;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Utils\Sms;
use Throwable;

class SmsChannel implements ChannelInterface
{
    public function send(Notification $notification): array
    {
        /** @var User|null $notifiable */
        $notifiable = $notification->notifiable;
        $mobile = $notification->data['mobile'] ?? $notifiable?->mobile;

        if (! $mobile) {
            return ['code' => 0, 'msg' => '收件人手机号为空'];
        }

        try {
            $sms = new Sms;
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => '短信服务初始化失败: '.$e->getMessage()];
        }

        if (! $sms->configured) {
            return ['code' => 0, 'msg' => '短信服务未配置'];
        }

        /** @var NotificationTemplate|null $template */
        $template = $notification->template;
        $templateCode = $template?->code;
        if (! $templateCode) {
            return ['code' => 0, 'msg' => '短信模板类型不存在'];
        }

        $payload = $notification->data['sms'] ?? $notification->data;
        $result = $sms->send($mobile, $templateCode, $payload);

        if (($result['code'] ?? 0) !== 1) {
            return ['code' => 0, 'msg' => $result['msg'] ?? '短信发送失败'];
        }

        return ['code' => 1];
    }

    public function isAvailable(): bool
    {
        try {
            $sms = new Sms;
        } catch (Throwable) {
            return false;
        }

        return $sms->configured;
    }
}
