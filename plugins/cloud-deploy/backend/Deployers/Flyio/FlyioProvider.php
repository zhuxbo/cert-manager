<?php

namespace Plugins\CloudDeploy\Deployers\Flyio;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class FlyioProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'flyio';
    }

    public function label(): string
    {
        return 'Fly.io';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }
}
