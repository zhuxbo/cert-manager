<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class TencentProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'tencent';
    }

    public function label(): string
    {
        return '腾讯云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'secret_id', 'label' => 'SecretId', 'required' => true],
            ['key' => 'secret_key', 'label' => 'SecretKey', 'required' => true, 'secret' => true],
            ['key' => 'project_id', 'label' => '项目 ID（选填）', 'type' => 'number', 'required' => false],
            ['key' => 'api_token', 'label' => 'EdgeOne Makers API Token（选填）', 'required' => false, 'secret' => true],
        ];
    }
}
