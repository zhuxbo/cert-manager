<?php

namespace Plugins\CloudDeploy\Deployers\Dogecloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 多吉云 provider（凭证 schema 对齐 certimate AccessConfigForDogeCloud：accessKey / secretKey）。
 */
class DogecloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'dogecloud';
    }

    public function label(): string
    {
        return '多吉云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key', 'label' => 'AccessKey', 'required' => true],
            ['key' => 'secret_key', 'label' => 'SecretKey', 'required' => true, 'secret' => true],
        ];
    }
}
