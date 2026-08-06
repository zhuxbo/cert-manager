<?php

namespace Plugins\CloudDeploy\Deployers\Kong;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Kong（自建 API 网关）provider。凭证含自建服务地址 server_url + Admin API Token。
 *
 * 对齐 certimate AccessConfigForKong：serverUrl / apiToken / allowInsecureConnections。
 */
class KongProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'kong';
    }

    public function label(): string
    {
        return 'Kong';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_token', 'label' => 'Admin API Token', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
