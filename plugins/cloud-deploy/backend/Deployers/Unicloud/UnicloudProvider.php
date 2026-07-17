<?php

namespace Plugins\CloudDeploy\Deployers\Unicloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class UnicloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'unicloud';
    }

    public function label(): string
    {
        return 'uniCloud';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'username', 'label' => '控制台账号', 'required' => true],
            ['key' => 'password', 'label' => '控制台密码', 'required' => true, 'secret' => true],
        ];
    }
}
