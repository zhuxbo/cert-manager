<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Order\Utils\DomainUtil;

/**
 * 自动续费/重签判定服务
 * 集中处理自动续费和重签的判定逻辑
 */
class AutoRenewService
{
    public function __construct(
        private readonly CnameDelegationService $delegationService
    ) {}

    /**
     * 检查自动续费是否会实际执行
     *
     * 条件：
     * - auto_renew = true（订单级或用户级）
     * - 产品 status=1 且 renew=1
     * - period_till - now() <= 15天（订单剩余时间不超过15天，走续费）
     */
    public function willAutoRenewExecute(Order $order, User $user): bool
    {
        // 检查 auto_renew 设置
        $autoRenewEnabled = $order->auto_renew ?? ($user->auto_settings['auto_renew'] ?? false);
        if (! $autoRenewEnabled) {
            return false;
        }

        // 检查产品是否支持续费
        $product = $order->product;
        if ($product->status != 1 || ! $product->renew) {
            return false;
        }

        // 订单剩余时间不超过15天时执行续费，超过15天走重签
        $periodTill = $order->period_till;
        if ($periodTill) {
            $daysRemaining = now()->diffInDays($periodTill, false);
            if ($daysRemaining > 15) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查自动重签是否会实际执行
     *
     * 条件：
     * - auto_reissue = true（订单级或用户级）
     * - 产品 reissue=1（重签不限产品启用状态，产品禁用仍可重签，与 AutoRenewCommand::getReissueOrders 对齐）
     * - period_till - now() > 15天（订单剩余时间超过15天，走重签）
     */
    public function willAutoReissueExecute(Order $order, User $user): bool
    {
        // 检查 auto_reissue 设置
        $autoReissueEnabled = $order->auto_reissue ?? ($user->auto_settings['auto_reissue'] ?? false);
        if (! $autoReissueEnabled) {
            return false;
        }

        // 检查产品是否支持重签（重签不限产品 status，仅看 reissue；与 getReissueOrders 只查 reissue==1 对齐）
        $product = $order->product;
        if (! $product->reissue) {
            return false;
        }

        // 订单剩余时间超过15天时执行重签，不超过15天走续费
        $periodTill = $order->period_till;
        if ($periodTill) {
            $daysRemaining = now()->diffInDays($periodTill, false);
            if ($daysRemaining <= 15) {
                return false;
            }
        }

        return true;
    }

    /**
     * 自动续签前置条件：确保所有域名都有有效委托
     *
     * 设计目的：尽可能让自动续签成功发起，而非严格拦截。
     * - 缺失委托记录时自动创建（首次创建后 DNS 未配置会验证失败，下次执行时重试）
     * - 创建策略：_dnsauth 按精确域名；_pki-validation/_certum 按根域（一条覆盖所有子域）
     * - DNS 验证采用宽松策略：所有 dnsTools + 本地检测全部尝试，任一匹配即有效
     *
     * @param  int  $userId  用户ID
     * @param  string  $domains  域名列表（逗号分隔）
     * @param  string  $ca  CA名称
     * @return bool 是否所有域名都有有效委托
     */
    public function checkDelegationValidity(int $userId, string $domains, string $ca): bool
    {
        $prefix = CnameDelegationService::getDelegationPrefixForCa($ca);
        $domainList = explode(',', trim($domains, ','));

        foreach ($domainList as $domain) {
            $domain = trim($domain);
            if (empty($domain)) {
                continue;
            }

            // 查找委托记录（不检查 valid 状态）
            $delegation = $this->delegationService->findDelegation($userId, $domain, $prefix);

            // 缺失则自动创建
            if (! $delegation) {
                $zone = $this->resolveZoneForCreation($domain, $prefix);
                $delegation = $this->delegationService->createOrGet($userId, $zone, $prefix);
            }

            // 即时验证 CNAME 记录
            if (! $this->delegationService->checkAndUpdateValidity($delegation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 根据前缀类型确定自动创建委托的 zone
     * - _dnsauth：精确域名（去通配符）
     * - _pki-validation/_certum：根域（一条覆盖所有子域）
     */
    private function resolveZoneForCreation(string $domain, string $prefix): string
    {
        // 规范化：去通配符前缀，转 Unicode 小写（与 findDelegation 保持一致）
        $domain = strtolower(DomainUtil::convertToUnicode(ltrim($domain, '*.')));

        // www.根域 归一为根域（条件与 findDelegation 完全一致）
        if ($prefix !== '_dnsauth' && str_starts_with($domain, 'www.')) {
            $stripped = substr($domain, 4);
            if (DomainUtil::getRootDomain($stripped) === $stripped) {
                $domain = $stripped;
            }
        }

        // _dnsauth 精确匹配，直接返回
        if ($prefix === '_dnsauth') {
            return $domain;
        }

        // 回落前缀：使用根域
        return DomainUtil::getRootDomain($domain) ?: $domain;
    }

    /**
     * 检查订单是否启用了自动续费
     *
     * @param  Order  $order  订单
     * @param  User  $user  用户
     * @return bool 是否启用
     */
    public function isAutoRenewEnabled(Order $order, User $user): bool
    {
        return $order->auto_renew ?? ($user->auto_settings['auto_renew'] ?? false);
    }
}
