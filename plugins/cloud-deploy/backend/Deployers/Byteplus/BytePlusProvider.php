<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * BytePlus（火山引擎国际版）provider。
 *
 * 凭证对齐 certimate AccessConfigForBytePlus（accessKeyId / secretAccessKey / projectName）：
 * - access_key_id      ← accessKeyId
 * - secret_access_key  ← secretAccessKey（secret）
 * - project_name       ← projectName（可选；多数端点的 ListXxx/Upload 接口用它做项目隔离）
 *
 * project_name 不在 provider 凭证里（certimate 也放在 AccessConfig 而非 ExtendedConfig），
 * 但本插件把它放进 **provider 凭证**（与 access/secret 同源），由各 deployer 从 $credentials 读取，
 * 不进 config schema（保持 config 仅承载部署目标维度，凭证维度全在 credentialSchema）。
 */
class BytePlusProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'byteplus';
    }

    public function label(): string
    {
        return 'BytePlus';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
            ['key' => 'project_name', 'label' => '项目名称（选填）', 'required' => false],
        ];
    }
}
