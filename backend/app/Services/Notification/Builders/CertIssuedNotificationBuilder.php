<?php

namespace App\Services\Notification\Builders;

use App\Bootstrap\ApiExceptions;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\CertificateProductType;
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

    public function build(NotificationIntent $intent, Model $notifiable): ?NotificationPayload
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
            ->whereHas('latestCert')
            ->find($orderId);

        if (! $order) {
            throw new RuntimeException('订单不存在');
        }

        $rawProductType = $order->product->getRawOriginal('product_type');
        if (is_string($rawProductType)
            && ! in_array($rawProductType, [Product::TYPE_SSL, Product::TYPE_SMIME], true)) {
            return null;
        }
        $productType = CertificateProductType::normalize($rawProductType);
        $productTypeLabel = CertificateProductType::label($productType);
        if ($order->latestCert->status !== 'active') {
            throw new RuntimeException('订单不存在或未签发');
        }

        $email = ($intent->context['email'] ?? '') ?: $notifiable->email;
        if (! $email) {
            throw new RuntimeException('邮箱为空');
        }

        $siteUrl = get_system_setting('site', 'url', '/');
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');

        $hasAttachment = in_array($productType, [Product::TYPE_SSL, Product::TYPE_SMIME], true);

        $commonName = $order->latestCert->common_name;
        $subject = "$commonName {$productTypeLabel} 证书已签发 [$siteName]";

        $data = [
            'username' => $notifiable->username,
            'site_url' => $siteUrl,
            'site_name' => $siteName,
            'product' => $order->product->name,
            'domain' => $order->latestCert->common_name,
            'order_id' => $order->id,
            'email' => $email,
            'product_type' => $productType,
            'product_type_label' => $productTypeLabel,
            'has_attachment' => $hasAttachment,
            'subject' => $subject,
            '_meta' => [
                'subject' => $subject,
                'is_html' => true,
            ],
        ];

        if ($hasAttachment) {
            $tempDir = $this->makeArchiveRootDir();
            $certName = $this->safeCertificateName(
                (string) $order->latestCert->common_name,
                $order->latestCert->id
            );
            $attachmentPath = $tempDir.'/'.$certName.'.zip';
            $zip = null;

            try {
                $zip = $this->makeZip();
                // ZipArchive::open()/close() 返回 bool/错误码、不抛异常。磁盘满（block 耗尽）最常在
                // close() 写盘期静默失败（addFromString 仅缓存在内存、close 才压缩刷盘）——不显式检查
                // 返回值会静默产出空/残 ZIP 标 SENT。故非 true 即抛 TransientBuildException（瞬态，可自愈）。
                if ($zip->open($attachmentPath, ZipArchive::CREATE) !== true) {
                    throw new TransientBuildException('创建证书压缩包失败');
                }
                if ($productType === Product::TYPE_SMIME) {
                    $password = $this->addSmimeCertToZip(
                        $order,
                        $zip,
                        $tempDir,
                        $certName.'/',
                        $certName,
                        'all'
                    );
                    if ($password === null) {
                        throw new RuntimeException('S/MIME PFX 密码生成失败');
                    }
                } else {
                    $this->addCertToZip($order, $zip, $tempDir);
                }
                if ($zip->close() !== true) {
                    throw new TransientBuildException('写入证书压缩包失败');
                }
            } catch (Throwable $e) {
                if ($zip instanceof ZipArchive) {
                    try {
                        $zip->close();
                    } catch (Throwable) {
                        // 清理优先，原异常保留。
                    }
                }
                File::deleteDirectory($tempDir);
                app(ApiExceptions::class)->logException($e);
                if ($e instanceof TransientBuildException) {
                    throw $e;
                }

                throw $e;
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
