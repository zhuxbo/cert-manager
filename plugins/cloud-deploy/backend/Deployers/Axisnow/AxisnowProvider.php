<?php

namespace Plugins\CloudDeploy\Deployers\Axisnow;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class AxisnowProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'axisnow';
    }

    public function label(): string
    {
        return 'AxisNow';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }
}
