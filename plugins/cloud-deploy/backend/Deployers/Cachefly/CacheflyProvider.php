<?php

namespace Plugins\CloudDeploy\Deployers\Cachefly;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class CacheflyProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'cachefly';
    }

    public function label(): string
    {
        return 'CacheFly';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }
}
