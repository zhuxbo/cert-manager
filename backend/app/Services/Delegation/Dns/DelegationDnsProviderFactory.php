<?php

declare(strict_types=1);

namespace App\Services\Delegation\Dns;

use InvalidArgumentException;

class DelegationDnsProviderFactory
{
    public function make(array $config): DelegationDnsProvider
    {
        $provider = $config['provider'] ?? null;

        if (! is_string($provider)) {
            throw new InvalidArgumentException('委托 DNS provider 配置无效');
        }

        return match (strtolower(trim($provider))) {
            'tencent' => new TencentDnsProvider($config),
            'cloudflare' => new CloudflareDnsProvider($config),
            'aliyun' => new AliyunDnsProvider($config),
            default => throw new InvalidArgumentException('不支持的委托 DNS provider'),
        };
    }
}
