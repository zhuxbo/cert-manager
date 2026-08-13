<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

class YandexcloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'yandexcloud';
    }

    public function label(): string
    {
        return 'Yandex Cloud';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'folder_id', 'label' => '文件夹 ID', 'required' => true],
            ['key' => 'service_account_key', 'label' => '服务账号授权密钥 JSON', 'required' => true, 'secret' => true],
        ];
    }
}
