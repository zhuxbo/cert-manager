<?php

namespace App\Services\Notification\Builders;

use App\Models\Admin;
use App\Models\Task as TaskModel;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class TaskFailedNotificationBuilder implements NotificationBuilderInterface
{
    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        if (! $notifiable instanceof Admin) {
            throw new RuntimeException('通知接收者必须为管理员');
        }

        $taskId = (int) ($intent->context['task_id'] ?? 0);
        if (! $taskId) {
            throw new RuntimeException('任务ID不存在');
        }

        $task = TaskModel::find($taskId);
        if (! $task) {
            throw new RuntimeException('任务不存在');
        }

        $params = $task->params ?? '';
        if (is_array($params)) {
            $params = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $result = $task->result ?? '';
        if (is_array($result)) {
            $result = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $subject = get_system_setting('site', 'name', 'SSL证书管理系统').'后台队列错误';
        $email = ($intent->context['admin_email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('管理员邮箱为空');
        }

        $data = [
            'order_id' => $task->order_id,
            'task_id' => $task->id,
            'task_action' => $task->action,
            'task_status' => $task->status,
            'attempts' => $task->attempts,
            'error_message' => $intent->context['error_message'] ?? '',
            'created_at' => $task->created_at?->toDateTimeString(),
            'executed_at' => $task->last_execute_at?->toDateTimeString(),
            'params' => $params,
            'result' => $result,
            'admin_email' => $email,
            'email' => $email,
            'subject' => $subject,
            '_meta' => [
                'email' => $email,
                'is_html' => true,
            ],
        ];

        return new NotificationPayload($data);
    }
}
