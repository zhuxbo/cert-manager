<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 天翼云 provider（凭证 schema 对齐 certimate AccessConfigForCTCCCloud：accessKeyId / secretAccessKey）。
 */
class CtcccloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'ctcccloud';
    }

    public function label(): string
    {
        return '天翼云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
        ];
    }
}
