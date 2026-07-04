<?php

namespace Plugins\CloudDeploy\Deployers\Bunny;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class BunnyProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'bunny';
    }

    public function label(): string
    {
        return 'Bunny';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true],
        ];
    }
}
