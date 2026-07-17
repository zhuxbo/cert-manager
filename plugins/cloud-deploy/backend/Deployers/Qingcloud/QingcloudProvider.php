<?php

namespace Plugins\CloudDeploy\Deployers\Qingcloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 青云 provider（凭证 schema 对齐 certimate AccessConfigForQingCloud：accessKeyId / secretAccessKey）。
 */
class QingcloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'qingcloud';
    }

    public function label(): string
    {
        return '青云 QingCloud';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
        ];
    }
}
