<?php

declare(strict_types=1);

namespace App\Services\Delegation;

use App\Models\CnameDelegation;
use App\Services\Order\Utils\DomainUtil;
use App\Services\Order\Utils\VerifyUtil;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CNAME 委托管理服务
 * 负责委托记录的创建、查询、健康检查等核心业务逻辑
 */
class CnameDelegationService
{
    use ApiResponse;

    /**
     * 创建或获取委托记录
     *
     * @param  int  $userId  用户ID
     * @param  string  $zone  委托域（可能是根域或子域）
     * @param  string  $prefix  委托前缀
     */
    public function createOrGet(int $userId, string $zone, string $prefix): CnameDelegation
    {
        // 规范化域名：转换为小写Unicode
        $zone = strtolower(DomainUtil::convertToUnicode($zone));

        // 查找是否已存在
        $delegation = CnameDelegation::where([
            'user_id' => $userId,
            'zone' => $zone,
            'prefix' => $prefix,
        ])->first();

        if ($delegation) {
            return $delegation;
        }

        // 生成 label（包含用户ID以确保唯一性）
        $delegatedFqdn = "$prefix.$zone";
        $label = $this->generateLabel($userId, $delegatedFqdn);

        // 创建新记录
        $delegation = new CnameDelegation([
            'user_id' => $userId,
            'zone' => $zone,
            'prefix' => $prefix,
            'label' => $label,
            'valid' => false,
            'fail_count' => 0,
            'last_error' => '',
        ]);

        $delegation->save();

        return $delegation;
    }

    /**
     * 生成哈希标签（SHA256 前32 字符）
     * 使用用户ID+域名组合确保每个用户的委托label唯一
     *
     * @param  int  $userId  用户ID
     * @param  string  $delegatedFqdn  委托FQDN（如 _dnsauth.example.com）
     * @return string 32 字符的哈希标签
     */
    protected function generateLabel(int $userId, string $delegatedFqdn): string
    {
        // 规范化：转小写ASCII
        $normalized = strtolower(DomainUtil::convertToAscii($delegatedFqdn));

        // 使用 用户ID + 域名 的组合字符串生成hash，确保不同用户的相同域名有不同label
        $uniqueString = $userId.':'.$normalized;

        // 使用 SHA256 的前 32 字符，既保证唯一性又不会太长
        return substr(hash('sha256', $uniqueString), 0, 32);
    }

    /**
     * 智能匹配委托记录（不检查 valid 状态，用于即时验证场景）
     *
     * 仅匹配完整 FQDN : _dnsauth
     * 优先匹配子域，未命中则回落到根域: _certum _pki-validation
     *
     * @param  int  $userId  用户ID
     * @param  string  $domain  域名（如 example.com 或 sub.example.com）
     * @param  string  $prefix  委托前缀
     */
    public function findDelegation(int $userId, string $domain, string $prefix): ?CnameDelegation
    {
        // 规范化域名，去掉通配符前缀
        $domain = ltrim(strtolower(DomainUtil::convertToUnicode($domain)), '*.');

        // ACME/DigiCert: 仅匹配完整 FQDN（不做 www 归一化，保留精确匹配语义）
        if ($prefix === '_dnsauth') {
            return CnameDelegation::where([
                'user_id' => $userId,
                'zone' => $domain,
                'prefix' => $prefix,
            ])->first();
        }

        // _certum/_pki-validation 等回落前缀：www.根域名 直接去除 www，避免无意义的查询
        if (str_starts_with($domain, 'www.')) {
            $stripped = substr($domain, 4);
            if (DomainUtil::getRootDomain($stripped) === $stripped) {
                $domain = $stripped;
            }
        }

        // 优先匹配子域
        $delegation = CnameDelegation::where([
            'user_id' => $userId,
            'zone' => $domain,
            'prefix' => $prefix,
        ])->first();

        if ($delegation) {
            return $delegation;
        }

        // 回落到根域
        $rootDomain = DomainUtil::getRootDomain($domain);
        if ($rootDomain && $rootDomain !== $domain) {
            return CnameDelegation::where([
                'user_id' => $userId,
                'zone' => $rootDomain,
                'prefix' => $prefix,
            ])->first();
        }

        return null;
    }

    /**
     * 智能匹配有效的委托记录
     *
     * 仅匹配完整 FQDN : _dnsauth
     * 优先匹配子域，未命中则回落到根域: _certum _pki-validation
     *
     * @param  int  $userId  用户ID
     * @param  string  $domain  域名（如 example.com 或 sub.example.com）
     * @param  string  $prefix  委托前缀
     */
    public function findValidDelegation(int $userId, string $domain, string $prefix): ?CnameDelegation
    {
        // 规范化域名，去掉通配符前缀
        $domain = ltrim(strtolower(DomainUtil::convertToUnicode($domain)), '*.');

        // ACME/DigiCert: 仅匹配完整 FQDN（不做 www 归一化，保留精确匹配语义）
        if ($prefix === '_dnsauth') {
            return CnameDelegation::where([
                'user_id' => $userId,
                'zone' => $domain,
                'prefix' => $prefix,
                'valid' => true,
            ])->first();
        }

        // _certum/_pki-validation 等回落前缀：www.根域名 直接去除 www，避免无意义的查询
        if (str_starts_with($domain, 'www.')) {
            $stripped = substr($domain, 4);
            if (DomainUtil::getRootDomain($stripped) === $stripped) {
                $domain = $stripped;
            }
        }

        // 优先匹配子域
        $delegation = CnameDelegation::where([
            'user_id' => $userId,
            'zone' => $domain,
            'prefix' => $prefix,
            'valid' => true,
        ])->first();

        if ($delegation) {
            return $delegation;
        }

        // 回落到根域
        $rootDomain = DomainUtil::getRootDomain($domain);
        if ($rootDomain && $rootDomain !== $domain) {
            return CnameDelegation::where([
                'user_id' => $userId,
                'zone' => $rootDomain,
                'prefix' => $prefix,
                'valid' => true,
            ])->first();
        }

        return null;
    }

    /**
     * 检查并更新委托有效性
     *
     * @param  CnameDelegation  $delegation  委托记录
     * @return bool 是否有效
     */
    public function checkAndUpdateValidity(CnameDelegation $delegation): bool
    {
        // DNS 查询需要 Punycode 格式
        $host = DomainUtil::convertToAscii("$delegation->prefix.$delegation->zone");
        $expectedTarget = $delegation->target_fqdn;

        try {
            // 执行 DNS CNAME 查询
            $valid = VerifyUtil::verifyCnameDelegation($host, $expectedTarget);

            $delegation->valid = $valid;
            $delegation->last_checked_at = now();

            if ($valid) {
                $delegation->fail_count = 0;
                $delegation->last_error = '';
            } else {
                // 硬截断 100：超过没有累加意义，且避免 TINYINT UNSIGNED 溢出
                $delegation->fail_count = min($delegation->fail_count + 1, 100);
                $delegation->last_error = 'CNAME记录不匹配或未配置';
                Log::warning('CNAME委托健康检查失败', [
                    'id' => $delegation->id,
                    'host' => $host,
                    'expected' => $expectedTarget,
                    'fail_count' => $delegation->fail_count,
                ]);
            }

            $delegation->save();

            return $valid;
        } catch (Throwable $e) {
            $delegation->valid = false;
            $delegation->fail_count = min($delegation->fail_count + 1, 100);
            // 截断异常消息，避免 SQLSTATE 报错回显嵌套 SQL 把 VARCHAR(255) 撑爆
            $delegation->last_error = mb_substr($e->getMessage(), 0, 200);
            $delegation->last_checked_at = now();
            $delegation->save();

            Log::error('CNAME委托健康检查异常', [
                'id' => $delegation->id,
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 附加 CNAME 配置指引
     *
     * @param  CnameDelegation  $delegation  委托记录
     */
    public function withCnameGuide(CnameDelegation $delegation): array
    {
        $data = $delegation->toArray();

        // 添加 CNAME 配置指引
        $data['cname_to'] = [
            'host' => "$delegation->prefix.$delegation->zone",
            'value' => $delegation->target_fqdn,
        ];

        return $data;
    }

    /**
     * 检测 TXT 记录冲突
     * CNAME 不能与其他记录类型共存，如果同名下有 TXT 记录则委托不生效
     */
    public function checkTxtConflict(CnameDelegation $delegation): ?string
    {
        $host = DomainUtil::convertToAscii("$delegation->prefix.$delegation->zone");

        try {
            $txtRecords = VerifyUtil::queryTxtRecords($host, direct: true);

            if (! empty($txtRecords)) {
                return "检测到 $host 存在TXT记录，TXT和CNAME同一名称同时存在会导致CNAME委托不生效，请删除TXT记录";
            }
        } catch (Throwable $e) {
            Log::warning('TXT冲突检测异常', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 根据 CA 获取委托验证前缀
     */
    public static function getDelegationPrefixForCa(string $ca): string
    {
        return match (strtolower($ca)) {
            'sectigo', 'comodo' => '_pki-validation',
            'certum' => '_certum',
            default => '_dnsauth',
        };
    }

    /**
     * 更新委托记录（可选功能：重新生成 label）
     *
     * @param  int  $userId  用户ID
     * @param  int  $id  委托记录ID
     * @param  array  $data  更新数据
     */
    public function update(int $userId, int $id, array $data): CnameDelegation
    {
        $delegation = CnameDelegation::where('user_id', $userId)
            ->where('id', $id)
            ->firstOrFail();

        // 如果需要重新生成 label
        if (isset($data['regen_label']) && $data['regen_label']) {
            $delegatedFqdn = "$delegation->prefix.$delegation->zone";
            $delegation->label = $this->generateLabel($userId, $delegatedFqdn);
            $delegation->valid = false; // 需要重新验证
            $delegation->fail_count = 0;
            $delegation->last_error = '';
        }

        $delegation->save();

        return $delegation;
    }
}
