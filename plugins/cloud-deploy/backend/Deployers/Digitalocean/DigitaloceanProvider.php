<?php

namespace Plugins\CloudDeploy\Deployers\Digitalocean;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class DigitaloceanProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'digitalocean';
    }

    public function label(): string
    {
        return 'DigitalOcean';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_token', 'label' => 'Access Token', 'required' => true, 'secret' => true],
        ];
    }
}
