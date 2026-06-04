<?php

namespace App\Services\Notification;

use App\Jobs\NotificationJob;
use App\Services\Notification\DTOs\NotificationIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class NotificationCenter
{
    public function __construct(
        protected TemplateSelector $templateSelector,
        protected ChannelManager $channelManager
    ) {}

    public function dispatch(NotificationIntent $intent): void
    {
        $notifiable = $this->resolveNotifiable($intent);
        if (! $notifiable) {
            // 接收者可能在 dispatch 调用时已被删除（账号注销 / 数据清理），属预期降级路径
            $this->logSkip($intent, '通知接收者不存在', level: 'debug');

            return;
        }

        $selection = $this->templateSelector->select($intent->code);
        if ($selection->isEmpty()) {
            $this->logSkip($intent, '通知模板不存在或未启用');

            return;
        }

        $template = $selection->template();

        foreach ($this->channelManager->channels() as $name => $channel) {
            try {
                if (! $channel->isAvailable()) {
                    $this->logSkip($intent, "通道不可用: $name");

                    continue;
                }

                if (! $channel->shouldSend($notifiable, $intent->code)) {
                    // 用户偏好关闭是常见且预期内行为，保持 debug 级别避免噪声
                    $this->logSkip($intent, "用户偏好关闭: $name", level: 'debug');

                    continue;
                }
            } catch (Throwable $e) {
                $this->logSkip($intent, "通道判定异常: $name", ['error' => $e->getMessage()]);

                continue;
            }

            try {
                $builderClass = $this->resolveBuilder($intent->code);
            } catch (RuntimeException) {
                $this->logSkip($intent, "通知构建器未配置: $intent->code");

                continue;
            }

            NotificationJob::dispatch(
                $intent->notifiableType,
                $intent->notifiableId,
                $template->id,
                $name,
                $intent->context,
                $builderClass
            )
                ->afterCommit()
                ->onQueue(Config::get('queue.names.notifications'));
        }
    }

    protected function resolveBuilder(string $code): string
    {
        $map = Config::get('notification.builders', []);

        if (array_key_exists($code, $map)) {
            $class = $map[$code];
            if (empty($class)) {
                throw new RuntimeException("通知构建器已禁用: $code");
            }

            return $class;
        }

        $class = Config::get('notification.default_builder');
        if (empty($class)) {
            throw new RuntimeException("未配置通知构建器: $code");
        }

        return $class;
    }

    protected function resolveNotifiable(NotificationIntent $intent): ?Model
    {
        $map = Config::get('notification.notifiables', []);
        $class = $map[$intent->notifiableType] ?? $intent->notifiableType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::find($intent->notifiableId);
    }

    /**
     * 通知跳过日志：
     *   - 默认 warning（生产可见，让运维感知 SMTP/模板/通道异常）
     *   - 显式传 level='debug' 用于"用户偏好关闭"这类预期内的噪声场景
     */
    protected function logSkip(NotificationIntent $intent, string $reason, array $meta = [], string $level = 'warning'): void
    {
        if ($level === 'debug' && ! config('app.debug')) {
            return;
        }

        Log::log($level, '[notification.skip] '.$reason, array_merge([
            'code' => $intent->code,
            'notifiable_type' => $intent->notifiableType,
            'notifiable_id' => $intent->notifiableId,
        ], $meta));
    }
}
