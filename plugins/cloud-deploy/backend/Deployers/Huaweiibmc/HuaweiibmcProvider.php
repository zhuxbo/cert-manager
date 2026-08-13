<?php

namespace Plugins\CloudDeploy\Deployers\Huaweiibmc;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class HuaweiibmcProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'huaweiibmc';
    }

    public function label(): string
    {
        return 'Huawei iBMC';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'host', 'label' => 'iBMC 主机', 'required' => true, 'destination' => true],
            ['key' => 'username', 'label' => '用户名', 'required' => true],
            ['key' => 'password', 'label' => '密码', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
