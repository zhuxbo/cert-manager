<?php

namespace Plugins\CloudDeploy\Deployers\Safeline;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 雷池 WAF（SafeLine，长亭科技自建 WAF）凭证 schema。
 *
 * 对齐 certimate AccessConfigForSafeLine：serverUrl + apiToken（`X-SLCE-API-TOKEN` 头鉴权）
 * + allow_insecure（自签证书自建设备常见，允许跳过 TLS 校验）。
 */
class SafelineProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'safeline';
    }

    public function label(): string
    {
        return '雷池 WAF';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true],
            ['key' => 'api_token', 'label' => 'API 令牌', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
