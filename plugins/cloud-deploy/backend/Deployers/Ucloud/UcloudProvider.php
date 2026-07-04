<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 优刻得（UCloud）provider。凭证对齐 certimate AccessConfigForUCloud：
 *   PublicKey（API 公钥）/ PrivateKey（API 私钥）/ ProjectId（项目 ID，可选）。
 *
 * 凭证 key 命名沿用插件下划线风格（public_key/private_key/project_id），与 UcloudRestClient
 * 构造参数及各 deployer makeClient 读取一致。
 */
class UcloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'ucloud';
    }

    public function label(): string
    {
        return '优刻得';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'public_key', 'label' => 'API 公钥', 'required' => true],
            ['key' => 'private_key', 'label' => 'API 私钥', 'required' => true, 'secret' => true],
            ['key' => 'project_id', 'label' => '项目 ID', 'required' => false],
        ];
    }
}
