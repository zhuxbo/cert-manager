<?php

namespace Plugins\CloudDeploy\Deployers\Mohua;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class MohuaProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'mohua';
    }

    public function label(): string
    {
        return '嘿华云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'username', 'label' => '账号', 'required' => true],
            ['key' => 'api_password', 'label' => 'API 密钥', 'required' => true, 'secret' => true],
        ];
    }
}
