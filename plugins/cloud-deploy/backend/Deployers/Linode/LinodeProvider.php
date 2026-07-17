<?php

namespace Plugins\CloudDeploy\Deployers\Linode;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class LinodeProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'linode';
    }

    public function label(): string
    {
        return 'Linode';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_token', 'label' => 'Access Token', 'required' => true, 'secret' => true],
        ];
    }
}
