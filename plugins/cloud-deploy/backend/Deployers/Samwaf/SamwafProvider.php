<?php

namespace Plugins\CloudDeploy\Deployers\Samwaf;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * SamWaf（开源 Go WAF）凭证 schema。
 *
 * 对齐 certimate AccessConfigForSamWAF：serverUrl + apiKey（`X-API-Key` 头鉴权，注意字段名 apiKey 非 apiToken）
 * + allow_insecure（自签证书自建设备常见，允许跳过 TLS 校验）。
 */
class SamwafProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'samwaf';
    }

    public function label(): string
    {
        return 'SamWaf';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_key', 'label' => 'API Key', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
