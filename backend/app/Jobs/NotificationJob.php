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
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Notification\NotificationRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 携密通知（如 user_created 初始密码）的 context 会作为构造参数序列化进队列存储。
 * 实现 ShouldBeEncrypted 让 Laravel 用 APP_KEY 加密整个 job payload，
 * 避免明文密码/凭据落入 jobs（执行前窗口）与 failed_jobs（长期留存）表。
 * sync 队列不序列化、直接执行，加密 marker 无副作用。
 */
class NotificationJob implements ShouldBeEncrypted, ShouldQueue
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
            // Job 延时执行期间接收者可能被删除（账号注销 / 数据清理），属预期降级路径
            $this->logSkip('通知接收者不存在', level: 'debug');

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
            // Builder 返回 null 表示合法的"无需发送"路径（如 CertExpire 委托有效时跳过）
            $this->logSkip('无需发送通知', level: 'debug');

            return;
        }

        $preparedPayload = $notificationRepository->preparePayload($template, $payload->data);
        $notification = $notificationRepository->createNotification($notifiable, $template, $preparedPayload);
        $notification->status = Notification::STATUS_SENDING;
        $notification->setRelation('notifiable', $notifiable);
        $notification->setRelation('template', $template);
        $notification->save();

        // 渲染期 transient：敏感字段（如初始密码）只注入内存供通道渲染，绝不写入 notifications.data。
        // 此时 DB 行已落地为不含 transient 的 $preparedPayload，下面 finally 再还原内存数据。
        if ($payload->transient !== []) {
            $notification->setAttribute('data', array_merge($preparedPayload, $payload->transient));
        }

        $isSuccessful = false;
        $result = [
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
        } finally {
            // 还原为不含 transient 的持久化数据，避免 updateSendResult 把敏感字段写回库
            if ($payload->transient !== []) {
                $notification->setAttribute('data', $preparedPayload);
            }
        }

        $notificationRepository->updateSendResult($notification, $result, $isSuccessful);

        // 兜底清理本次 build 生成的临时文件（如 CertIssued 的证书 ZIP）。
        // Builder 按通道各 build 一次，附件类 payload 会为每个通道各生成一份临时文件；
        // 仅消费附件的通道（mail）在 send 内清理自己那份，非 mail 通道（插件注入）无清理逻辑，
        // 会导致含私钥的临时 ZIP 泄漏。此处对所有通道、含发送失败路径统一兜底清理，
        // 与 MailChannel 内清理幂等（cleanupPath 以 is_dir/is_file 守卫，重复删除安全跳过）。
        $this->cleanupTempPaths($payload);
    }

    /**
     * 清理 payload._meta.cleanup_paths 指向的临时文件/目录（兜底，不抛异常）。
     */
    protected function cleanupTempPaths(NotificationPayload $payload): void
    {
        $cleanupPaths = $payload->data['_meta']['cleanup_paths'] ?? [];
        if (! is_array($cleanupPaths) || $cleanupPaths === []) {
            return;
        }

        foreach ($cleanupPaths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            try {
                if (is_dir($path)) {
                    File::deleteDirectory($path);
                } elseif (is_file($path)) {
                    File::delete($path);
                }
            } catch (Throwable $e) {
                // 清理失败不应影响通知主流程，记录后继续
                app(ApiExceptions::class)->logException($e);
            }
        }
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

    /**
     * 通知跳过日志：
     *   - 默认 warning（生产可见，让运维感知模板缺失 / builder 异常等）
     *   - 显式传 level='debug' 用于预期内的噪声场景
     */
    protected function logSkip(string $reason, string $level = 'warning'): void
    {
        if ($level === 'debug' && ! config('app.debug')) {
            return;
        }

        Log::log($level, '[notification.dispatch.skip] '.$reason, [
            'template_id' => $this->templateId,
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
        ]);
    }
}
