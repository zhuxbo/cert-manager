<?php

namespace Plugins\CloudDeploy\Deployers\Cdnfly;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class CdnflyProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'cdnfly';
    }

    public function label(): string
    {
        return 'Cdnfly';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_key', 'label' => '用户端 API Key', 'required' => true, 'secret' => true],
            ['key' => 'api_secret', 'label' => '用户端 API Secret', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
