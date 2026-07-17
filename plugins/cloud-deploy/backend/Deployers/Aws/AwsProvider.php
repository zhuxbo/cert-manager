<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class AwsProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'aws';
    }

    public function label(): string
    {
        return '亚马逊云科技';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
        ];
    }
}
