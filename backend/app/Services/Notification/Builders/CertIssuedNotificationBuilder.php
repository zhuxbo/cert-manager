<?php

namespace App\Services\Notification\Builders;

use App\Bootstrap\ApiExceptions;
use App\Models\Order;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Notification\Exceptions\TransientBuildException;
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

                $zip = $this->makeZip();
                // ZipArchive::open()/close() 返回 bool/错误码、不抛异常。磁盘满（block 耗尽）最常在
                // close() 写盘期静默失败（addFromString 仅缓存在内存、close 才压缩刷盘）——不显式检查
                // 返回值会静默产出空/残 ZIP 标 SENT。故非 true 即抛 TransientBuildException（瞬态，可自愈）。
                if ($zip->open($attachmentPath, ZipArchive::CREATE) !== true) {
                    throw new TransientBuildException('创建证书压缩包失败');
                }
                $this->addCertToZip($order, $zip, $tempDir);
                if ($zip->close() !== true) {
                    throw new TransientBuildException('写入证书压缩包失败');
                }
            } catch (Throwable $e) {
                File::deleteDirectory($tempDir);
                app(ApiExceptions::class)->logException($e);
                // IO 类失败一律归瞬态：inode 耗尽/盘满/临时 IO 异常，恢复后重跑 build 自愈。
                throw new TransientBuildException('生成证书附件失败', 0, $e);
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

    /**
     * 构造 ZipArchive 实例（测试注入缝：子类可覆盖返回 close() 静默返 false 的桩，
     * 精确验证「close 返回值检查 → 抛瞬态」。同 MailChannel::makeMail() 既有范式）。
     */
    protected function makeZip(): ZipArchive
    {
        return new ZipArchive;
    }
}
