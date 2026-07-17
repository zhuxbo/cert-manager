<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 火山引擎（VolcEngine）provider。
 *
 * 凭证对齐 certimate domain.AccessConfigForVolcEngine：accessKeyId / secretAccessKey / projectName（可选）。
 * region 是各端点的 config 字段（非凭证），故不在 credentialSchema 中。
 */
class VolcProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'volcengine';
    }

    public function label(): string
    {
        return '火山引擎';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
            ['key' => 'project_name', 'label' => '项目名称（可选）', 'required' => false],
        ];
    }
}
