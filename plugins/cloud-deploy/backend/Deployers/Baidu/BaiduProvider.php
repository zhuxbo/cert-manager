<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 百度智能云 provider。凭证对齐 certimate AccessConfigForBaiduCloud（AccessKeyId / SecretAccessKey）。
 */
class BaiduProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'baidu';
    }

    public function label(): string
    {
        return '百度智能云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
        ];
    }
}
