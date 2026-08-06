<?php

namespace Plugins\CloudDeploy\Deployers\Apisix;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * APISIX（自建 API 网关）provider。凭证含自建服务地址 server_url + Admin API Key。
 *
 * 对齐 certimate AccessConfigForAPISIX：serverUrl / apiKey / allowInsecureConnections。
 */
class ApisixProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'apisix';
    }

    public function label(): string
    {
        return 'APISIX';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_key', 'label' => 'Admin API Key', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
