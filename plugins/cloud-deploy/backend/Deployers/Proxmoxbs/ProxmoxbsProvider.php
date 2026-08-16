<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxbs;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class ProxmoxbsProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'proxmoxbs';
    }

    public function label(): string
    {
        return 'Proxmox Backup Server';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
            ['key' => 'api_token_secret', 'label' => 'API Token Secret', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
