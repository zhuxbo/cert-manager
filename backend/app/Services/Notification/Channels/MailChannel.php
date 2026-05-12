<?php

namespace App\Services\Notification\Channels;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Utils\Email;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use PHPMailer\PHPMailer\Exception;
use Throwable;

class MailChannel implements ChannelInterface
{
    /**
     * @throws Exception
     */
    public function send(Notification $notification): array
    {
        /** @var User|null $notifiable */
        $notifiable = $notification->notifiable;
        $email = $notification->data['email'] ?? $notifiable?->email;
        if (! $email) {
            return ['code' => 0, 'msg' => '收件人邮箱为空'];
        }

        $meta = $notification->data['_meta'] ?? [];
        $attachments = Arr::get($meta, 'attachments', []);
        $cleanupPaths = Arr::get($meta, 'cleanup_paths', []);

        $mail = new Email;
        $mail->isSMTP();
        $mail->isHTML((bool) ($meta['is_html'] ?? true));

        if (! $mail->configured) {
            return ['code' => 0, 'msg' => '邮件服务未配置'];
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
                return ['code' => 0, 'msg' => '邮件发送失败'];
            }
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $e->getMessage()];
        } finally {
            foreach ($cleanupPaths as $path) {
                $this->cleanupPath($path);
            }
        }

        return ['code' => 1];
    }

    protected function attachFile(Email $mail, array $attachment): array
    {
        $path = $attachment['path'] ?? null;
        if (! $path || ! file_exists($path)) {
            return ['code' => 0, 'msg' => '邮件附件不存在或已被删除'];
        }

        $name = $attachment['name'] ?? basename($path);
        try {
            $mail->addAttachment($path, $name);
        } catch (Exception $e) {
            return ['code' => 0, 'msg' => $e->getMessage()];
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
}
