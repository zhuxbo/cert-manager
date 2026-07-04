<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Zenlayer provider（凭证 schema 对齐 certimate AccessConfigForZenlayer：
 * accessKeyId / accessKeyPassword / resourceGroupId（选填））。
 *
 * resourceGroupId 作为凭证级可选项（创建证书时用于归属资源组），与 certimate AccessConfig 一致。
 */
class ZenlayerProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'zenlayer';
    }

    public function label(): string
    {
        return 'Zenlayer';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'access_key_password', 'label' => 'AccessKeyPassword', 'required' => true, 'secret' => true],
            ['key' => 'resource_group_id', 'label' => '资源组 ID（选填）', 'required' => false],
        ];
    }
}
