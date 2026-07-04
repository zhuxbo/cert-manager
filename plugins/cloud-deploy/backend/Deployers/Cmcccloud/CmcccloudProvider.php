<?php

namespace Plugins\CloudDeploy\Deployers\Cmcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 移动云 provider（凭证 schema 对齐 certimate AccessConfigForCMCCCloud：accessKeyId / accessKeySecret）。
 */
class CmcccloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'cmcccloud';
    }

    public function label(): string
    {
        return '移动云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'access_key_secret', 'label' => 'AccessKeySecret', 'required' => true, 'secret' => true],
        ];
    }
}
