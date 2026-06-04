<?php

namespace App\Services\Notification\Builders;

use App\Bootstrap\ApiExceptions;
use App\Models\Order;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Order\Traits\ActionFileTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

class CertIssuedNotificationBuilder implements NotificationBuilderInterface
{
    use ActionFileTrait;

    public function build(NotificationIntent $intent, Model $notifiable): NotificationPayload
    {
        if (! $notifiable instanceof User) {
            throw new RuntimeException('通知接收者必须为用户');
        }

        $orderId = (int) ($intent->context['order_id'] ?? 0);
        if (! $orderId) {
            throw new RuntimeException('订单ID不存在');
        }

        $order = Order::with(['user', 'product', 'latestCert'])
            ->whereHas('user')
            ->whereHas('product')
            ->whereHas('latestCert', fn ($query) => $query->where('status', 'active'))
            ->find($orderId);

        if (! $order) {
            throw new RuntimeException('订单不存在或未签发');
        }

        $email = ($intent->context['email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('邮箱为空');
        }

        $siteUrl = get_system_setting('site', 'url', '/');
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');

        // 获取产品类型，仅 SSL 证书需要生成附件
        $productType = $order->product->product_type ?? 'ssl';
        $hasAttachment = $productType === 'ssl';

        // 根据产品类型生成邮件主题
        $commonName = $order->latestCert->common_name;
        $subject = match ($productType) {
            'smime' => "$commonName S/MIME 证书已签发 [$siteName]",
            'codesign' => "$commonName 代码签名证书已签发 [$siteName]",
            default => "$commonName 域名SSL证书已签发 [$siteName]",
        };

        $data = [
            'username' => $notifiable->username,
            'site_url' => $siteUrl,
            'site_name' => $siteName,
            'product' => $order->product->name,
            'domain' => $order->latestCert->common_name,
            'order_id' => $order->id,
            'email' => $email,
            'product_type' => $productType,
            'has_attachment' => $hasAttachment,
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        // SSL 证书才生成 ZIP 附件
        if ($hasAttachment) {
            $random = sprintf('%04x%04x', mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
            $tempDir = storage_path('temp-certs/'.$random);
            $attachmentPath = $tempDir.'/'.str_replace('*', 'STAR', $order->latestCert->common_name).'.zip';

            try {
                mkdir($tempDir, 0755, true);

                $zip = new ZipArchive;
                $zip->open($attachmentPath, ZipArchive::CREATE);
                $this->addCertToZip($order, $zip, $tempDir);
                $zip->close();
            } catch (Throwable $e) {
                File::deleteDirectory($tempDir);
                app(ApiExceptions::class)->logException($e);
                throw new RuntimeException('生成证书附件失败');
            }

            $data['_meta']['attachments'] = [
                [
                    'path' => $attachmentPath,
                    'name' => basename($attachmentPath),
                ],
            ];
            $data['_meta']['cleanup_paths'] = [$tempDir];
        }

        return new NotificationPayload($data);
    }
}
