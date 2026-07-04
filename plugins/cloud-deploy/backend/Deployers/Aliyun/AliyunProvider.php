<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class AliyunProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'aliyun';
    }

    public function label(): string
    {
        return '阿里云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'access_key_secret', 'label' => 'AccessKey Secret', 'required' => true, 'secret' => true],
        ];
    }
}
