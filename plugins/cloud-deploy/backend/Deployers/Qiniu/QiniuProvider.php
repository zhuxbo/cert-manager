<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class QiniuProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'qiniu';
    }

    public function label(): string
    {
        return '七牛云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key', 'label' => 'AccessKey', 'required' => true],
            ['key' => 'secret_key', 'label' => 'SecretKey', 'required' => true, 'secret' => true],
        ];
    }
}
