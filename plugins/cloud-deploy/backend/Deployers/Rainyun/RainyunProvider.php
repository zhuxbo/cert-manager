<?php

namespace Plugins\CloudDeploy\Deployers\Rainyun;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class RainyunProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'rainyun';
    }

    public function label(): string
    {
        return '雨云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API 密钥', 'required' => true, 'secret' => true],
        ];
    }
}
