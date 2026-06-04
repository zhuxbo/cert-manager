<?php

namespace App\Services\Notification\Channels;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;

interface ChannelInterface
{
    /**
     * 发送通知并返回结果
     *
     * @return array{code: int, msg?: string} 返回格式：['code' => 1, 'msg' => '可选消息'] 成功，['code' => 0, 'msg' => '错误消息'] 失败
     */
    public function send(Notification $notification): array;

    /**
     * 检查通道是否可用（系统层面：服务/凭据是否已配置）
     */
    public function isAvailable(): bool;

    /**
     * 检查是否应该向该接收者发送指定事件的通知（用户偏好层面）
     *
     * 各通道自己决定如何判断：mail 读 notification_settings；插件通道读自己的存储。
     */
    public function shouldSend(Model $notifiable, string $code): bool;
}
