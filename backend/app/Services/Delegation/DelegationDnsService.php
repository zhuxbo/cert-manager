<?php

declare(strict_types=1);

namespace App\Services\Delegation;

use App\Services\Delegation\Dns\DelegationDnsProvider;
use App\Services\Delegation\Dns\DelegationDnsProviderFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 委托验证 DNS 管理服务
 * 负责管理委托验证过程中的 DNS TXT 记录设置和清理
 */
class DelegationDnsService
{
    public function __construct(
        private readonly DelegationDnsProviderFactory $factory = new DelegationDnsProviderFactory,
        private readonly DelegationConfigService $configs = new DelegationConfigService,
    ) {}

    /**
     * 按 label 直接设置 TXT 记录（用于自动委托验证）
     *
     * @param  string  $proxyDomain  代理域名
     * @param  string  $label  哈希标签
     * @param  array  $values  TXT 值数组
     * @return bool 是否成功设置
     */
    public function setTxtByLabel(string $proxyDomain, string $label, array $values): bool
    {
        if (empty($proxyDomain) || empty($label) || empty($values)) {
            return false;
        }

        try {
            return $this->provider($proxyDomain)->upsertTxt(
                $label,
                array_values(array_unique($values)),
            );
        } catch (Throwable $e) {
            Log::error('委托 TXT 记录写入失败', [
                'proxy_domain' => $proxyDomain,
                'label' => $label,
                'exception' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * 按 label 删除 TXT 记录
     *
     * @param  string  $proxyDomain  代理域名
     * @param  string  $label  哈希标签
     */
    public function deleteTxtByLabel(string $proxyDomain, string $label): void
    {
        if (empty($proxyDomain) || empty($label)) {
            return;
        }

        $this->provider($proxyDomain)->deleteTxt($label);
    }

    public function getAllTxtRecords(string $proxyDomain): array
    {
        return $this->provider($proxyDomain)->allTxt();
    }

    private function provider(string $proxyDomain): DelegationDnsProvider
    {
        return $this->factory->make($this->configs->get($proxyDomain));
    }
}
