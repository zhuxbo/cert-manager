<?php

namespace Plugins\CloudDeploy\Deployers\Netlify;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class NetlifyProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'netlify';
    }

    public function label(): string
    {
        return 'Netlify';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }
}
