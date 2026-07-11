<?php

namespace App\Services\Notification\Channels;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Utils\Email;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use PHPMailer\PHPMailer\Exception;
use Throwable;

class MailChannel implements ChannelInterface
{
    /**
     * 发送结果携带 retryable 标记供 NotificationJob 决定是否重试（M4）：
     *  - retryable=false（永久）：配置类问题重试无益——空邮箱 / 未配置 / 附件问题。
     *    判永久防新装机 failed_jobs 风暴 + CertIssued 每轮重生成含私钥 ZIP。
     *  - retryable=true（瞬态）：SMTP send 失败 / 发送异常，下轮 build 可自愈。
     *  缺省不含该键（如成功 code=1，或插件通道返回）→ NotificationJob 视作 false（不重试），安全。
     *
     * @throws Exception
     */
    public function send(Notification $notification): array
    {
        /** @var User|null $notifiable */
        $notifiable = $notification->notifiable;
        $email = $notification->data['email'] ?? $notifiable?->email;
        if (! $email) {
            return ['code' => 0, 'msg' => '收件人邮箱为空', 'retryable' => false];
        }

        $meta = $notification->data['_meta'] ?? [];
        $attachments = Arr::get($meta, 'attachments', []);
        $cleanupPaths = Arr::get($meta, 'cleanup_paths', []);

        $mail = $this->makeMail();
        $mail->isSMTP();
        $mail->isHTML((bool) ($meta['is_html'] ?? true));

        if (! $mail->configured) {
            return ['code' => 0, 'msg' => '邮件服务未配置', 'retryable' => false];
        }

        /** @var NotificationTemplate|null $template */
        $template = $notification->template;
        $subject = $meta['subject'] ?? $template?->name ?? '通知'; // @phpstan-ignore nullsafe.neverNull
        $body = $meta['content'] ?? $template?->render($notification->data ?? []) ?? '';

        $mail->addAddress($email, $notifiable?->username);
        $mail->setSubject($subject);
        $mail->Body = $body;

        try {
            foreach ($attachments as $attachment) {
                $attachResult = $this->attachFile($mail, $attachment);
                if (($attachResult['code'] ?? 0) !== 1) {
                    return $attachResult;
                }
            }

            if (! $mail->send()) {
                $errorInfo = trim((string) $mail->ErrorInfo);

                // SMTP send 失败 → 瞬态可重试
                return ['code' => 0, 'msg' => $errorInfo !== '' ? "邮件发送失败: $errorInfo" : '邮件发送失败', 'retryable' => true];
            }
        } catch (Throwable $e) {
            // 发送期异常（网络/SMTP 抖动）→ 瞬态可重试
            return ['code' => 0, 'msg' => $e->getMessage(), 'retryable' => true];
        } finally {
            foreach ($cleanupPaths as $path) {
                $this->cleanupPath($path);
            }
        }

        return ['code' => 1];
    }

    /**
     * 构造 Email 实例（测试注入缝：子类可覆盖以注入 mock，避免真实 SMTP 连接）。
     *
     * @throws Throwable
     */
    protected function makeMail(): Email
    {
        return new Email;
    }

    protected function attachFile(Email $mail, array $attachment): array
    {
        // 附件问题一律判永久（retryable=false）：防 CertIssued 每轮重生成含私钥 ZIP 的重试风暴；
        // 证书仍可面板下载、FAILED 行 UI 可见（F5 权衡：默认永久档）。
        $path = $attachment['path'] ?? null;
        if (! $path || ! file_exists($path)) {
            return ['code' => 0, 'msg' => '邮件附件不存在或已被删除', 'retryable' => false];
        }

        $name = $attachment['name'] ?? basename($path);
        try {
            $mail->addAttachment($path, $name);
        } catch (Exception $e) {
            return ['code' => 0, 'msg' => $e->getMessage(), 'retryable' => false];
        }

        return ['code' => 1];
    }

    protected function cleanupPath(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (is_dir($path)) {
            File::deleteDirectory($path);
        } elseif (is_file($path)) {
            File::delete($path);
        }
    }

    public function isAvailable(): bool
    {
        try {
            $mail = new Email;
        } catch (Throwable) {
            return false;
        }

        return $mail->configured;
    }

    public function shouldSend(Model $notifiable, string $code): bool
    {
        if (empty($notifiable->email)) {
            return false;
        }

        if (method_exists($notifiable, 'allowsNotification')) {
            return (bool) $notifiable->allowsNotification($code);
        }

        return true;
    }
}
