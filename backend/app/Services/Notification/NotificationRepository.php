<?php

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * 通知记录仓储
 *
 * 负责通知记录的持久化操作，包括数据准备、创建记录和状态更新
 * 主要用于 NotificationJob 中处理通知的数据库操作
 */
class NotificationRepository
{
    /**
     * 准备通知数据载荷
     *
     * 将业务数据与模板结合，生成最终写入数据库的通知数据：
     * 1. 渲染模板内容
     * 2. 设置标题和内容到 _meta 字段
     * 3. 保留原始业务数据供后续使用
     *
     * @param  NotificationTemplate  $template  通知模板
     * @param  array  $data  业务数据，可包含 _meta、subject 等字段
     * @return array 准备好的数据载荷
     */
    public function preparePayload(NotificationTemplate $template, array $data): array
    {
        $meta = $data['_meta'] ?? [];
        unset($data['_meta']);

        $subject = $data['subject'] ?? $template->name;
        $meta['subject'] = $meta['subject'] ?? $subject;

        return array_merge($data, [
            '_meta' => $meta,
        ]);
    }

    /**
     * 创建通知记录
     *
     * 在数据库中创建一条新的通知记录，初始状态为 pending
     *
     * @param  Model  $notifiable  通知接收者（User、Admin 等）
     * @param  NotificationTemplate  $template  通知模板
     * @param  array  $payload  准备好的数据载荷（通常来自 preparePayload）
     * @return Notification 创建的通知记录
     */
    public function createNotification(Model $notifiable, NotificationTemplate $template, array $payload): Notification
    {
        /** @var User $notifiable */
        /** @var Notification */
        return $notifiable->notifications()->create([
            'template_id' => $template->id,
            'data' => $payload,
            'status' => Notification::STATUS_PENDING,
        ]);
    }

    /**
     * 更新通知发送结果
     *
     * 将各通道的发送结果写入通知记录，并更新通知状态
     *
     * @param  Notification  $notification  通知记录
     * @param  array  $result  单通道发送结果 ['channel' => 'mail', 'status' => 'sent', ...]
     * @param  bool  $isSuccessful  是否发送成功
     */
    public function updateSendResult(Notification $notification, array $result, bool $isSuccessful): void
    {
        $notification->data = array_merge($notification->data ?? [], [
            'result' => $result,
        ]);

        if ($isSuccessful) {
            $notification->markAsSent();
        } else {
            $notification->markAsFailed();
        }
    }
}
