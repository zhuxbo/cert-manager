<?php

namespace Plugins\CloudDeploy\Deployers\Gcore;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class GcoreProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'gcore';
    }

    public function label(): string
    {
        return 'Gcore';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }
}
