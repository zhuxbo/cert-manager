<?php

namespace App\Http\Traits;

use Throwable;
use Yansongda\Pay\Pay;

trait PaymentConfigTrait
{
    protected array $config = [];

    protected const string CACHE_KEY_PREFIX = 'pay_config_';

    protected const int CACHE_DAYS = 365; // 缓存有效期

    /**
     * 获取指定支付方式的配置，检查配置中的空值并从数据库更新，同时检查证书文件
     * 优先从缓存读取，如果缓存不存在则从配置文件和数据库中获取并写入缓存
     *
     * @throws Throwable
     */
    protected function getPayConfig(string $type, bool $forceRefresh = false): void
    {
        // 判断是否需要强制刷新缓存
        $refresh = request()->input('refresh_pay_config');
        $forceRefresh = isset($refresh) ? $refresh === '1' : $forceRefresh;
        $cacheKey = self::CACHE_KEY_PREFIX.$type;

        // 如果不需要强制刷新且缓存中存在配置，则直接返回配置
        if (! $forceRefresh && cache()->has($cacheKey)) {
            $config = cache()->get($cacheKey);
            // 更新当前配置
            if (! empty($config)) {
                $this->config[$type]['default'] = $config;
                Pay::config($this->config);

                return;
            }
        }

        // 从配置文件获取基础配置
        $config = $this->config;
        if (empty($config[$type]['default'])) {
            $config = config('pay', []);
            $this->config = $config;
        }

        // 从数据库获取最新配置
        if (! empty($config[$type]['default'])) {
            $defaultConfig = $config[$type]['default'];
            $dbConfig = get_system_setting($type);

            if (! empty($dbConfig)) {
                // 更新配置中的空值
                foreach ($defaultConfig as $key => $value) {
                    if ((empty($value) || $forceRefresh) && isset($dbConfig[$key]) && $key !== 'wechat_public_cert_path') {
                        $defaultConfig[$key] = $dbConfig[$key];
                    }
                }
            }

            if (empty($defaultConfig['notify_url'])) {
                // 使用当前请求的根URL
                $baseUrl = request()->root();
                $defaultConfig['notify_url'] = $baseUrl.'/callback/'.$type;
            }

            // 检查并处理证书文件
            if ($type === 'alipay') {
                $certPaths = [
                    'appCertPublicKey' => 'alipayAppCertPublicKey.crt',
                    'certPublicKeyRSA2' => 'alipayCertPublicKeyRSA2.crt',
                    'rootCert' => 'alipayRootCert.crt',
                ];

                // 存储证书文件
                foreach ($certPaths as $configKey => $fileName) {
                    $filePath = storage_path('pay/'.$fileName);
                    if ((! file_exists($filePath) || $forceRefresh) && isset($dbConfig[$configKey])) {
                        if (! is_dir(storage_path('pay'))) {
                            mkdir(storage_path('pay'), 0755, true);
                        }
                        file_put_contents($filePath, $dbConfig[$configKey]);
                    }
                }
            } elseif ($type === 'wechat') {
                $certPaths = [
                    'apiclientKey' => 'wechatApiclientKey.pem',
                    'apiclientCert' => 'wechatApiclientCert.pem',
                ];

                // 存储证书文件
                foreach ($certPaths as $configKey => $fileName) {
                    $filePath = storage_path('pay/'.$fileName);
                    if ((! file_exists($filePath) || $forceRefresh) && isset($dbConfig[$configKey])) {
                        if (! is_dir(storage_path('pay'))) {
                            mkdir(storage_path('pay'), 0755, true);
                        }
                        file_put_contents($filePath, $dbConfig[$configKey]);
                    }
                }

                // 特殊处理微信支付平台证书
                $defaultConfig['wechat_public_cert_path'] = [];

                // 从数据库获取微信支付公钥ID和内容
                if (isset($dbConfig['publicKeyId']) && isset($dbConfig['publicKey'])) {
                    $publicKeyId = $dbConfig['publicKeyId'];
                    $publicKeyContent = $dbConfig['publicKey'];

                    if (! empty($publicKeyId) && ! empty($publicKeyContent)) {
                        $fileName = 'wechatPublicKey.pem';
                        $filePath = storage_path('pay/'.$fileName);

                        if (! file_exists($filePath) || $forceRefresh) {
                            if (! is_dir(storage_path('pay'))) {
                                mkdir(storage_path('pay'), 0755, true);
                            }
                            file_put_contents($filePath, $publicKeyContent);
                        }

                        $defaultConfig['wechat_public_cert_path'][$publicKeyId] = $filePath;
                    }
                }
            }

            // 更新配置并写入缓存
            $this->config[$type]['default'] = $defaultConfig;
            cache()->put($cacheKey, $defaultConfig, now()->addDays(self::CACHE_DAYS));

            // 配置支付SDK
            Pay::config($this->config);
        }
    }

    /**
     * 清除支付配置缓存
     */
    public function clearConfigCache(): void
    {
        $this->clearAlipayCache();
        $this->clearWechatCache();
        $this->success(['message' => '支付配置缓存已清除']);
    }

    /**
     * 清除支付宝缓存 删除证书文件
     */
    public function clearAlipayCache(): void
    {
        // 清除支付宝配置缓存
        cache()->forget(self::CACHE_KEY_PREFIX.'alipay');

        // 删除支付宝证书文件
        $certFiles = [
            'alipayAppCertPublicKey.crt',
            'alipayCertPublicKeyRSA2.crt',
            'alipayRootCert.crt',
        ];

        foreach ($certFiles as $file) {
            $filePath = storage_path('pay/'.$file);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    /**
     * 清除微信支付缓存 删除证书文件
     */
    public function clearWechatCache(): void
    {
        // 清除微信配置缓存
        cache()->forget(self::CACHE_KEY_PREFIX.'wechat');

        // 删除微信证书文件
        $certFiles = [
            'wechatApiclientKey.pem',
            'wechatApiclientCert.pem',
            'wechatPublicKey.pem',
        ];

        foreach ($certFiles as $file) {
            $filePath = storage_path('pay/'.$file);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    /**
     * 微信支付公钥序列号请求头参数。
     *
     * 配置了微信支付公钥（publicKeyId + publicKey 俱全）时，给微信 v3 请求注入 _serial_no，
     * yansongda 据此设置 Wechatpay-Serial 请求头，微信端遂以公钥签名应答，
     * 推进商户后台「应答使用公钥比例」直至可完成验签方式切换。
     *
     * 发头条件与 getPayConfig 注册本地公钥的条件对称（二者俱全）：仅填 publicKeyId
     * 未填 publicKey 内容时本地无公钥可验签，若发头会让应答切公钥却验签失败，故此时
     * 返回空数组不发头（应答仍用平台证书、本地可验、保持可用）。未配置时同样回退，
     * 行为与切换前一致。
     */
    protected function wechatSerial(): array
    {
        $wechat = get_system_setting('wechat');

        return (! empty($wechat['publicKeyId']) && ! empty($wechat['publicKey']))
            ? ['_serial_no' => $wechat['publicKeyId']]
            : [];
    }
}
