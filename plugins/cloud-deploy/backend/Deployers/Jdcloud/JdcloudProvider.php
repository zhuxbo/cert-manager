<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 京东云 provider（key=jdcloud）。
 *
 * 凭证对齐 certimate internal/domain/access.go AccessConfigForJDCloud：
 *   { accessKeyId, accessKeySecret }
 * 这里映射为插件凭证键 access_key_id / access_key_secret（access_key_secret 标 secret）。
 */
class JdcloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'jdcloud';
    }

    public function label(): string
    {
        return '京东云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'access_key_secret', 'label' => 'AccessKey Secret', 'required' => true, 'secret' => true],
        ];
    }
}
