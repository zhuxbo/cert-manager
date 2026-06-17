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
     * 行为完全由 CA 决定（经 ca_map 派生 prefix + exact）：
     * - exact=true：仅匹配完整 FQDN（不做 www 归一化，拒绝回落根域）
     * - exact=false：www 归一 + 子域优先 + 回落根域
     *
     * @param  int  $userId  用户ID
     * @param  string  $domain  域名（如 example.com 或 sub.example.com）
     * @param  string  $ca  CA 名称（不区分大小写）
     */
    public function findDelegation(int $userId, string $domain, string $ca): ?CnameDelegation
    {
        return $this->findByResolution($userId, $domain, $ca, false);
    }

    /**
     * 智能匹配有效的委托记录（仅返回 valid=true）
     *
     * 行为完全由 CA 决定（经 ca_map 派生 prefix + exact），语义同 findDelegation。
     *
     * @param  int  $userId  用户ID
     * @param  string  $domain  域名（如 example.com 或 sub.example.com）
     * @param  string  $ca  CA 名称（不区分大小写）
     */
    public function findValidDelegation(int $userId, string $domain, string $ca): ?CnameDelegation
    {
        return $this->findByResolution($userId, $domain, $ca, true);
    }

    /**
     * 按 CA 解析查找委托记录（统一查找内核，全 ca_map 驱动）
     *
     * 由 ca 派生 prefix + exact：
     * - exact=true：只查完整 FQDN（zone=domain，不归一 www、不回落根域）
     * - exact=false：www 归一 → 子域优先 → 回落根域
     *
     * @param  int  $userId  用户ID
     * @param  string  $domain  域名（如 example.com 或 sub.example.com）
     * @param  string  $ca  CA 名称（不区分大小写）
     * @param  bool  $onlyValid  是否仅匹配 valid=true 的记录
     */
    private function findByResolution(int $userId, string $domain, string $ca, bool $onlyValid): ?CnameDelegation
    {
        $prefix = self::getDelegationPrefixForCa($ca);
        $exact = $this->isExactForCa($ca);

        // 规范化域名，去掉通配符前缀
        $domain = ltrim(strtolower(DomainUtil::convertToUnicode($domain)), '*.');

        // exact：仅匹配完整 FQDN（不做 www 归一化、不回落根域）
        if ($exact) {
            return $this->findExact($userId, $domain, $prefix, $onlyValid);
        }

        // 非 exact：www.根域名 直接去除 www，避免无意义的查询
        if (str_starts_with($domain, 'www.')) {
            $stripped = substr($domain, 4);
            if (DomainUtil::getRootDomain($stripped) === $stripped) {
                $domain = $stripped;
            }
        }

        // 优先匹配子域
        $delegation = $this->findExact($userId, $domain, $prefix, $onlyValid);
        if ($delegation) {
            return $delegation;
        }

        // 回落到根域
        $rootDomain = DomainUtil::getRootDomain($domain);
        if ($rootDomain && $rootDomain !== $domain) {
            return $this->findExact($userId, $rootDomain, $prefix, $onlyValid);
        }

        return null;
    }

    /**
     * 按精确 (zone, prefix) 对查找委托记录（无任何推断：不归一 www、不回落根域）
     *
     * 用于已从 DCV host 解析出确切 zone+prefix 的场景（AutoDcvTxtService），
     * 以及 findByResolution 内部按层查询。zone 一律按调用方传入的精确值匹配。
     *
     * @param  int  $userId  用户ID
     * @param  string  $zone  委托域（精确值，调用方负责规范化）
     * @param  string  $prefix  委托前缀
     * @param  bool  $onlyValid  是否仅匹配 valid=true 的记录
     */
    public function findExact(int $userId, string $zone, string $prefix, bool $onlyValid = false): ?CnameDelegation
    {
        $where = [
            'user_id' => $userId,
            'zone' => $zone,
            'prefix' => $prefix,
        ];

        if ($onlyValid) {
            $where['valid'] = true;
        }

        return CnameDelegation::where($where)->first();
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
     * 委托前缀白名单（从 config 派生：ca_map 全部 prefix + default prefix，去重）
     *
     * 单一来源供所有"前缀有效性校验"复用（请求验证、host 解析白名单等），
     * 避免硬编码 `['_certum', '_pki-validation', '_dnsauth']` 在多处漂移。
     *
     * @return string[]
     */
    public static function supportedPrefixes(): array
    {
        return array_values(array_unique([
            ...array_column(config('delegation.ca_map'), 'prefix'),
            config('delegation.default.prefix'),
        ]));
    }

    /**
     * 根据 CA 获取委托验证前缀（config 驱动，未知 CA 回落 default）
     *
     * 注意：用 config() 的 dot 访问而非数组下标，避免未知 ca 触发 undefined-key warning。
     * `false ?? x` 不回落、`null ?? x` 才回落——未知 ca（config 返回 null）才落 default。
     */
    public static function getDelegationPrefixForCa(string $ca): string
    {
        return config('delegation.ca_map.'.strtolower($ca).'.prefix')
            ?? config('delegation.default.prefix');
    }

    /**
     * 根据 CA 判断是否精确匹配子域（config 驱动，未知 CA 回落 default）
     *
     * exact 是 CA 的属性而非 prefix 的属性：同一 prefix（如 _dnsauth）在不同 CA 下
     * 要求可能不同（有的拒绝回落、有的允许）。一律以 ca_map 为准，禁止 prefix 推断。
     *
     * 注意：`false ?? x` 不回落、`null ?? x` 才回落——已配置的 ca（值为 false）按配置走，
     * 仅未知 ca（config 返回 null）才落 default。
     */
    public function isExactForCa(string $ca): bool
    {
        return config('delegation.ca_map.'.strtolower($ca).'.exact')
            ?? config('delegation.default.exact');
    }

    /**
     * 根据 CA 确定创建委托时的 zone（创建期使用，全 ca_map 驱动）
     *
     * 规范化（去通配符、转 Unicode 小写）后：
     * - exact=true：返回精确域名（不归一 www）
     * - exact=false：www.根域 归一为根域后，返回根域（一条覆盖所有子域）
     *
     * @param  string  $domain  域名（如 example.com 或 sub.example.com）
     * @param  string  $ca  CA 名称（不区分大小写）
     */
    public function resolveZone(string $domain, string $ca): string
    {
        // 规范化：去通配符前缀，转 Unicode 小写（与 findByResolution 保持一致）
        $domain = strtolower(DomainUtil::convertToUnicode(ltrim($domain, '*.')));

        // exact：精确域名，直接返回（不归一 www、不取根域）
        if ($this->isExactForCa($ca)) {
            return $domain;
        }

        // 非 exact：www.根域 归一为根域（条件与 findByResolution 完全一致）
        if (str_starts_with($domain, 'www.')) {
            $stripped = substr($domain, 4);
            if (DomainUtil::getRootDomain($stripped) === $stripped) {
                $domain = $stripped;
            }
        }

        // 非 exact：使用根域
        return DomainUtil::getRootDomain($domain) ?: $domain;
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
