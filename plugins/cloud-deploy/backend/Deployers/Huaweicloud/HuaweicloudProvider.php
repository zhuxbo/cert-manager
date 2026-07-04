<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 华为云 provider（凭证 schema 对齐 certimate AccessConfigForHuaweiCloud：accessKeyId / secretAccessKey
 * + 可选 enterpriseProjectId）。
 *
 * region 不在 provider 凭证里 —— 它是「按端点 + 资源」维度的部署配置（每个 deployer 的 configSchema 各自声明），
 * 与 certimate 一致（access.go 只含 AK/SK/企业项目，DeployerConfig 才带 Region）。
 */
class HuaweicloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'huaweicloud';
    }

    public function label(): string
    {
        return '华为云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
            ['key' => 'enterprise_project_id', 'label' => '企业项目 ID（选填）', 'required' => false],
        ];
    }
}
