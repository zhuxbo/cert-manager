<?php

namespace App\Console\Commands;

use App\Services\Notification\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * E3 充值渠道健康（P2）。
 *
 * 调度：schedule:payment-health，每天 05:00（freeze 期 skip）。只读零副作用（不改
 * PaymentConfigTrait/365d 缓存）。
 *
 * 逐渠道判「启用」（核心身份字段非空：alipay=app_id / wechat=mch_id），启用渠道只校验
 * 已配置的支付证书；空证书字段视为该证书能力未启用，不做完整性告警。已存在落盘证书时
 * 优先检查 SDK 实际使用的文件，文件尚未生成时才回退检查设置值。校验口径与 SDK 一致：
 *  - 叶证书解析 X.509 并检查 notAfter；支付宝 RSA2 公钥按验签公钥解析，若本身是
 *    X.509 证书再检查 notAfter。
 *  - rootCert 按 SDK 的多证书 bundle 方式逐张解析，不做到期预警。
 *
 * 状态指纹（内容型）：同一批到期/解析异常项 TTL 内不重发；新增异常项 → 指纹变 → 立即再发。
 * details 只放字段名/日期，绝不含证书内容（Builder denylist/PEM 掩码二次兜底）。
 */
class PaymentHealthCommand extends Command
{
    protected $signature = 'schedule:payment-health';

    protected $description = '充值渠道已配置支付证书到期与解析监控（只读）';

    private const DEDUPE_KEY = 'payment_health';

    /**
     * 渠道定义：identity=启用判定字段，cert_fields=设置字段到 SDK 路径及校验类型的映射。
     */
    private const CHANNELS = [
        'alipay' => [
            'identity' => 'app_id',
            'cert_fields' => [
                'certPublicKeyRSA2' => ['path' => 'alipay_public_cert_path', 'type' => 'public_key'],
                'appCertPublicKey' => ['path' => 'app_public_cert_path', 'type' => 'certificate'],
                'rootCert' => ['path' => 'alipay_root_cert_path', 'type' => 'root_bundle'],
            ],
        ],
        'wechat' => [
            'identity' => 'mch_id',
            'cert_fields' => [
                'apiclientCert' => ['path' => 'mch_public_cert_path', 'type' => 'certificate'],
            ],
        ],
    ];

    public function handle(): int
    {
        if (! config('monitoring.payment_health.enabled', true)) {
            return self::SUCCESS;
        }

        $warnDays = (int) config('monitoring.payment_health.cert_warn_days', 30);

        // details 拍平为标量串（键名避 denylist 子串：channel_expiring/channel_invalid）
        $details = [];
        foreach (self::CHANNELS as $type => $def) {
            $result = $this->checkChannel($type, $def, $warnDays);
            if ($result === null) {
                continue; // 未启用
            }
            [$expiring, $invalid] = $result;
            if (! empty($expiring)) {
                $details[$type.'_expiring'] = implode(', ', $expiring);
            }
            if (! empty($invalid)) {
                $details[$type.'_invalid'] = implode(', ', $invalid);
            }
        }

        if (empty($details)) {
            // 全部正常 → 清键（恢复后再异常立即告警）
            app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);

            return self::SUCCESS;
        }

        Log::warning('[payment_health] 支付渠道证书异常', ['fields' => array_keys($details)]);
        app(SystemAlert::class)->send(
            'payment_health',
            '充值渠道证书异常',
            '支付渠道存在即将到期或无法解析的已配置证书，请检查：'.implode('; ', array_keys($details)),
            $details,
            self::DEDUPE_KEY,
            (int) config('monitoring.payment_health.dedupe_ttl_hours', 168),
            // 内容指纹（新增到期项则指纹变、立即再发）：不传固定指纹
        );

        return self::SUCCESS;
    }

    /**
     * 校验单渠道。未启用返回 null；否则返回 [expiring[], invalid[]]。
     *
     * @return array{0: array<int, string>, 1: array<int, string>}|null
     */
    private function checkChannel(string $type, array $def, int $warnDays): ?array
    {
        $config = get_system_setting($type);
        if (! is_array($config) || empty($config[$def['identity']])) {
            return null; // 未启用（核心身份字段空）
        }

        $expiring = [];
        $invalid = [];
        $threshold = now()->addDays($warnDays)->timestamp;
        foreach ($def['cert_fields'] as $field => $check) {
            $pem = $config[$field] ?? '';
            if (empty($pem)) {
                continue; // 未配置即未启用该证书能力，不告警
            }

            // 支付请求实际读取落盘文件；健康检查必须与其保持同一数据源。
            $runtimePath = config("pay.{$type}.default.{$check['path']}");
            if (is_string($runtimePath) && is_file($runtimePath)) {
                $pem = is_readable($runtimePath) ? file_get_contents($runtimePath) : false;
            }

            if ($check['type'] === 'root_bundle') {
                if (! $this->isValidRootBundle((string) $pem)) {
                    $invalid[] = $field.'(解析失败)';
                }

                continue;
            }

            $parsed = openssl_x509_parse((string) $pem);
            if ($check['type'] === 'public_key' && openssl_pkey_get_public((string) $pem) !== false) {
                // RSA2 验签同时接受公钥和证书；纯公钥没有 notAfter。
                if ($parsed === false) {
                    continue;
                }
            } elseif ($parsed === false) {
                $invalid[] = $field.'(解析失败)';

                continue;
            }
            $notAfter = (int) ($parsed['validTo_time_t'] ?? 0);
            if ($notAfter < $threshold) {
                $expiring[] = $field.'('.date('Y-m-d', $notAfter).')';
            }
        }

        return [$expiring, $invalid];
    }

    private function isValidRootBundle(string $pem): bool
    {
        $certificates = array_filter(
            explode('-----END CERTIFICATE-----', $pem),
            static fn (string $certificate): bool => trim($certificate) !== '',
        );
        if ($certificates === []) {
            return false;
        }

        foreach ($certificates as $certificate) {
            if (openssl_x509_parse($certificate.'-----END CERTIFICATE-----') === false) {
                return false;
            }
        }

        return true;
    }
}
