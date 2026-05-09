<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Bootstrap\ApiExceptions;
use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Notification\Builders\NotificationBuilderInterface;
use App\Services\Notification\ChannelManager;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $notifiableType,
        protected int $notifiableId,
        protected int $templateId,
        protected string $channel,
        protected array $context,
        protected string $builderClass
    ) {}

    public function handle(NotificationRepository $notificationRepository, ChannelManager $channelManager): void
    {
        $template = NotificationTemplate::find($this->templateId);
        if (! $template || $template->status !== 1) {
            $this->logSkip('模板不存在或已禁用');

            return;
        }

        $notifiable = $this->resolveNotifiable();
        if (! $notifiable) {
            $this->logSkip('通知接收者不存在');

            return;
        }

        $builder = app($this->builderClass);
        if (! $builder instanceof NotificationBuilderInterface) {
            $this->logSkip('通知构建器未实现接口');

            return;
        }

        $intent = new NotificationIntent(
            $template->code,
            $this->notifiableType,
            $this->notifiableId,
            $this->context
        );

        try {
            $payload = $builder->build($intent, $notifiable);
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);
            $this->logSkip('构建通知数据失败');

            return;
        }

        if (! $payload) {
            $this->logSkip('无需发送通知');

            return;
        }

        // 通道验证：确保模板支持该通道
        if (! in_array($this->channel, $template->channels ?? [])) {
            $this->logSkip("模板不支持该通道: $this->channel");

            return;
        }

        $preparedPayload = $notificationRepository->preparePayload($template, $payload->data);
        $notification = $notificationRepository->createNotification($notifiable, $template, $preparedPayload);
        $notification->status = Notification::STATUS_SENDING;
        $notification->setRelation('notifiable', $notifiable);
        $notification->setRelation('template', $template);
        $notification->save();

        $isSuccessful = false;
        $result = [
            'channel' => $this->channel,
            'status' => Notification::STATUS_FAILED,
            'message' => null,
            'timestamp' => now()->toDateTimeString(),
        ];

        try {
            // Channel::send() 返回格式: ['code' => 1, 'msg' => '可选消息'] 成功，['code' => 0, 'msg' => '错误消息'] 失败
            $sendResult = $channelManager->channel($this->channel)->send($notification);
            $success = $sendResult['code'] === 1;
            $result['status'] = $success ? Notification::STATUS_SENT : Notification::STATUS_FAILED;
            $result['message'] = $sendResult['msg'] ?? null;
            $result['timestamp'] = now()->toDateTimeString();
            $isSuccessful = $success;
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);
            $result['message'] = '发送失败，请稍后重试';
            $result['timestamp'] = now()->toDateTimeString();
        }

        $notificationRepository->updateSendResult($notification, $result, $isSuccessful);
    }

    protected function resolveNotifiable(): ?Model
    {
        $map = Config::get('notification.notifiables', []);
        $class = $map[$this->notifiableType] ?? $this->notifiableType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::find($this->notifiableId);
    }

    protected function logSkip(string $reason): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug('[notification.dispatch.skip] '.$reason, [
            'template_id' => $this->templateId,
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
        ]);
    }
}
