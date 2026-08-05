<?php

namespace Plugins\CloudDeploy\Deployers\Dokploy;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Dokploy（自建 PaaS）provider。凭证含自建服务地址 server_url + API Key。
 *
 * 对齐 certimate AccessConfigForDokploy：serverUrl / apiKey / allowInsecureConnections。
 */
class DokployProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'dokploy';
    }

    public function label(): string
    {
        return 'Dokploy';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
