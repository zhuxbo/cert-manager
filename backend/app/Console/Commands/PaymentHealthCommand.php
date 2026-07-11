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
 * 逐渠道判「启用」（核心身份字段非空：alipay=app_id / wechat=mch_id），启用渠道校验：
 *  - 配置完整性：必填字段非空（缺失计入 missing）。
 *  - 证书 notAfter：对叶证书走 openssl_x509_parse（PHP 内建非 exec，不经 BinaryLocator），
 *    < now + cert_warn_days 计入 expiring；parse 返 false → 计入完整性异常（不 crash）。
 *  - rootCert 只查非空、跳过 notAfter（常为多证书 bundle，openssl_x509_parse 仅解析首张会
 *    误判；根证 20+ 年有效风险低）。
 *
 * 状态指纹（内容型）：同一批到期/缺失项 TTL 内不重发；新增到期项 → 指纹变 → 立即再发。
 * details 只放字段名/日期，绝不含证书内容（Builder denylist/PEM 掩码二次兜底）。
 */
class PaymentHealthCommand extends Command
{
    protected $signature = 'schedule:payment-health';

    protected $description = '充值渠道支付证书到期与配置完整性监控（只读）';

    private const DEDUPE_KEY = 'payment_health';

    /**
     * 渠道定义：identity=启用判定字段，required=必填完整性字段，
     * cert_fields=需查 notAfter 的叶证书字段（rootCert 排除，只查非空）。
     */
    private const CHANNELS = [
        'alipay' => [
            'identity' => 'app_id',
            'required' => ['app_secret_cert', 'appCertPublicKey', 'certPublicKeyRSA2', 'rootCert'],
            'cert_fields' => ['certPublicKeyRSA2', 'appCertPublicKey'],
        ],
        'wechat' => [
            'identity' => 'mch_id',
            'required' => ['mch_secret_key', 'apiclientKey', 'apiclientCert'],
            'cert_fields' => ['apiclientCert'],
        ],
    ];

    public function handle(): int
    {
        if (! config('monitoring.payment_health.enabled', true)) {
            return self::SUCCESS;
        }

        $warnDays = (int) config('monitoring.payment_health.cert_warn_days', 30);

        // details 拍平为标量串（键名避 denylist 子串：channel_expiring/channel_missing）
        $details = [];
        foreach (self::CHANNELS as $type => $def) {
            $result = $this->checkChannel($type, $def, $warnDays);
            if ($result === null) {
                continue; // 未启用
            }
            [$expiring, $missing] = $result;
            if (! empty($expiring)) {
                $details[$type.'_expiring'] = implode(', ', $expiring);
            }
            if (! empty($missing)) {
                $details[$type.'_missing'] = implode(', ', $missing);
            }
        }

        if (empty($details)) {
            // 全部正常 → 清键（恢复后再异常立即告警）
            app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);

            return self::SUCCESS;
        }

        Log::warning('[payment_health] 支付渠道证书/配置异常', ['fields' => array_keys($details)]);
        app(SystemAlert::class)->send(
            'payment_health',
            '充值渠道证书/配置异常',
            '支付渠道存在到期证书或缺失配置，请检查：'.implode('; ', array_keys($details)),
            $details,
            self::DEDUPE_KEY,
            (int) config('monitoring.payment_health.dedupe_ttl_hours', 168),
            // 内容指纹（新增到期项则指纹变、立即再发）：不传固定指纹
        );

        return self::SUCCESS;
    }

    /**
     * 校验单渠道。未启用返回 null；否则返回 [expiring[], missing[]]。
     *
     * @return array{0: array<int, string>, 1: array<int, string>}|null
     */
    private function checkChannel(string $type, array $def, int $warnDays): ?array
    {
        $config = get_system_setting($type);
        if (! is_array($config) || empty($config[$def['identity']])) {
            return null; // 未启用（核心身份字段空）
        }

        $missing = [];
        foreach ($def['required'] as $field) {
            if (empty($config[$field])) {
                $missing[] = $field;
            }
        }

        $expiring = [];
        $threshold = now()->addDays($warnDays)->timestamp;
        foreach ($def['cert_fields'] as $field) {
            $pem = $config[$field] ?? '';
            if (empty($pem)) {
                continue; // 缺失已计入 missing
            }
            $parsed = openssl_x509_parse((string) $pem);
            if ($parsed === false) {
                // 坏证书串：计入完整性异常，不 crash
                $missing[] = $field.'(解析失败)';

                continue;
            }
            $notAfter = (int) ($parsed['validTo_time_t'] ?? 0);
            if ($notAfter < $threshold) {
                $expiring[] = $field.'('.date('Y-m-d', $notAfter).')';
            }
        }

        return [$expiring, $missing];
    }
}
