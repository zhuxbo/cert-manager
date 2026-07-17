<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxve;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Proxmox VE provider。凭证含自建服务地址 server_url + API Token（ID + Secret）。
 *
 * 对齐 certimate AccessConfigForProxmoxVE：serverUrl / apiToken / apiTokenSecret / allowInsecureConnections。
 * api_token 形如 `user@realm!tokenid`，api_token_secret 为该 token 的 secret。
 */
class ProxmoxveProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'proxmoxve';
    }

    public function label(): string
    {
        return 'Proxmox VE';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true],
            ['key' => 'api_token', 'label' => 'API Token（user@realm!tokenid）', 'required' => true, 'secret' => true],
            ['key' => 'api_token_secret', 'label' => 'API Token Secret', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
