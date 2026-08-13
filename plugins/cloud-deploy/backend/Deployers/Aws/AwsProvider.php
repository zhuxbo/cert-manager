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
            [
                'key' => 'auth_method',
                'label' => '认证方式',
                'type' => 'select',
                'default' => 'accesskey',
                'options' => [
                    ['label' => 'Access Key', 'value' => 'accesskey'],
                    ['label' => 'EC2 实例角色（IMDSv2）', 'value' => 'imds'],
                ],
            ],
            [
                'key' => 'access_key_id',
                'label' => 'AccessKey ID',
                'required_when' => ['key' => 'auth_method', 'equals' => 'accesskey'],
                'visible_when' => ['key' => 'auth_method', 'equals' => 'accesskey'],
            ],
            [
                'key' => 'secret_access_key',
                'label' => 'SecretAccessKey',
                'required_when' => ['key' => 'auth_method', 'equals' => 'accesskey'],
                'visible_when' => ['key' => 'auth_method', 'equals' => 'accesskey'],
                'secret' => true,
            ],
        ];
    }
}
