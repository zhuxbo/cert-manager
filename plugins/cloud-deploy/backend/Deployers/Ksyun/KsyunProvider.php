<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 金山云 provider（凭证 schema 对齐 certimate AccessConfigForKsyun：accessKeyId / secretAccessKey）。
 */
class KsyunProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'ksyun';
    }

    public function label(): string
    {
        return '金山云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
        ];
    }
}
