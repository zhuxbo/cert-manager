<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;

/**
 * 支付配置缓存与落盘证书的单一失效入口。
 *
 * 支付配置有两处副本：Cache 里的 pay_config_{type}（365 天）与 storage/pay 下的证书文件。
 * PaymentConfigTrait::getPayConfig 只在文件缺失或显式 forceRefresh 时才重写证书文件，
 * 所以只清缓存不删文件会留下"设置已换新证书、磁盘还是旧证书"的静默不一致。
 * 支付设置组保存后、以及 admin 手动清理时都必须走这里，两处副本一起失效。
 */
class PayConfigCache
{
    /** 支持的支付类型（= 设置组名） */
    public const TYPES = ['alipay', 'wechat'];

    private const CACHE_KEY_PREFIX = 'pay_config_';

    /** 各支付类型由设置值落盘的证书文件 */
    private const CERT_FILES = [
        'alipay' => [
            'alipayAppCertPublicKey.crt',
            'alipayCertPublicKeyRSA2.crt',
            'alipayRootCert.crt',
        ],
        'wechat' => [
            'wechatApiclientKey.pem',
            'wechatApiclientCert.pem',
            'wechatPublicKey.pem',
        ],
    ];

    /**
     * 清除指定支付类型的配置缓存并删除其落盘证书（下次 getPayConfig 从设置重新落盘）。
     *
     * 非支付类型的组名传入时静默忽略，调用方无需先判断。
     */
    public static function forget(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            return;
        }

        Cache::forget(self::CACHE_KEY_PREFIX.$type);

        foreach (self::CERT_FILES[$type] as $file) {
            $path = storage_path('pay/'.$file);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * 清除全部支付类型。
     */
    public static function forgetAll(): void
    {
        foreach (self::TYPES as $type) {
            self::forget($type);
        }
    }
}
