<?php

namespace Plugins\CloudDeploy\Deployers\Vercel;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class VercelProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'vercel';
    }

    public function label(): string
    {
        return 'Vercel';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_access_token', 'label' => 'API Access Token', 'required' => true, 'secret' => true],
            ['key' => 'team_id', 'label' => 'Team ID（选填）', 'required' => false],
        ];
    }
}
