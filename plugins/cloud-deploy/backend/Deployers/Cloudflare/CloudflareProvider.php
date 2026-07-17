<?php

namespace Plugins\CloudDeploy\Deployers\Cloudflare;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class CloudflareProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }
}
