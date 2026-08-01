<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;

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
        $autoRenewEnabled = $order->auto_renew ?? $user->auto_settings['auto_renew'];
        if (! $autoRenewEnabled) {
            return false;
        }

        // 检查产品是否支持续费
        $product = $order->product;
        // status 有 integer cast；宽松/严格不等在模型输入上等价，仅忽略这一种等价变异。
        if ($product->status != 1 || ! $product->renew) { // @pest-mutate-ignore: NotEqualToNotIdentical
            return false;
        }

        // 仅 ssl 产品走自动续费；smime/codesign/docsign 等无域名验证产品退出选单，改由 cert_expire 提醒
        // （product_type NULL 视为 ssl，与 Product::isSSL / getRenewOrders 白名单口径一致）
        if (! $product->isSSL()) {
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
        $autoReissueEnabled = $order->auto_reissue ?? $user->auto_settings['auto_reissue'];
        if (! $autoReissueEnabled) {
            return false;
        }

        // 检查产品是否支持重签（重签不限产品 status，仅看 reissue；与 getReissueOrders 只查 reissue==1 对齐）
        $product = $order->product;
        if (! $product->reissue) {
            return false;
        }

        // 仅 ssl 产品走自动重签；非 ssl（smime/codesign/docsign）退出选单，改由 cert_expire 提醒
        // （product_type NULL 视为 ssl，与 Product::isSSL / getReissueOrders 白名单口径一致）
        if (! $product->isSSL()) {
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
     * 判断订单是否会被 AutoRenewCommand 妥善处理（成功续签/重签 或 失败时发 auto_renew_failed）。
     *
     * 派发侧（ExpireCommand）与重查侧（CertExpireNotificationBuilder）三腿谓词单一源，杜绝口径漂移
     * （漂移致「派发了 user 但重查为空 → 整封静默漏发」或反向双发，见 skills/backend/auto-renew.md
     * 「派发/重查同源」红线）。三道 gate：
     *   ① $autoRenewFailedEnabled=false（模板停用）→ false：AutoRenewCommand 发不出失败通知，不排除，
     *      回落发 cert_expire（双腿同断防静默过期）。布尔由各调用方循环外算一次传入（不逐单查模板）。
     *   ② latestCert.channel==='api' → false：下游系统自行续费/重签，AutoRenewCommand 不处理
     *      （getRenewOrders/getReissueOrders 已 channel!=api 过滤）。
     *   ③ willAutoRenewExecute||willAutoReissueExecute。
     *
     * 调用方须保证 $order->latestCert / user / product 非空（whereHas 预筛 + 汇总 builder 二次过滤）。
     */
    public function willBeHandledByAutoRenew(Order $order, User $user, bool $autoRenewFailedEnabled): bool
    {
        if (! $autoRenewFailedEnabled) {
            return false;
        }

        if ($order->latestCert->channel === 'api') {
            return false;
        }

        return $this->willAutoRenewExecute($order, $user)
            || $this->willAutoReissueExecute($order, $user);
    }

    /**
     * 自动续签前置条件：确保所有域名都有有效委托
     *
     * 设计目的：尽可能让自动续签成功发起，而非严格拦截。
     * - 缺失委托记录时自动创建（首次创建后 DNS 未配置会验证失败，下次执行时重试）
     * - 创建/查找策略全 ca_map 驱动：exact 按精确域名；非 exact 按根域（一条覆盖所有子域）
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
        $domainList = explode(',', $domains);

        foreach ($domainList as $domain) {
            $domain = trim($domain);
            if (empty($domain)) {
                continue;
            }

            // 查找委托记录（不检查 valid 状态，全 ca_map 驱动）
            $delegation = $this->delegationService->findDelegation($userId, $domain, $ca);

            // 缺失则自动创建（zone 由 ca 派生：exact 精确域名 / 非 exact 根域）
            if (! $delegation) {
                $zone = $this->delegationService->resolveZone($domain, $ca);
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
     * 检查订单是否启用了自动续费
     *
     * @param  Order  $order  订单
     * @param  User  $user  用户
     * @return bool 是否启用
     */
    public function isAutoRenewEnabled(Order $order, User $user): bool
    {
        return $order->auto_renew ?? $user->auto_settings['auto_renew'];
    }
}
