<?php

namespace Plugins\CloudDeploy\Deployers\Baishan;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 白山云 provider（凭证 schema 对齐 certimate AccessConfigForBaishan：apiToken）。
 */
class BaishanProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'baishan';
    }

    public function label(): string
    {
        return '白山云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'api_token', 'label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }
}
