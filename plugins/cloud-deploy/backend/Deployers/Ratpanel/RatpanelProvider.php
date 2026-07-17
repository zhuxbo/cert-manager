<?php

namespace Plugins\CloudDeploy\Deployers\Ratpanel;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 耗子面板（RatPanel / AcePanel）凭证 schema。
 *
 * 对齐 certimate AccessConfigForRatPanel：serverUrl + accessTokenId（数字）+ accessToken（HMAC 密钥）
 * + allow_insecure（自签证书面板常见，允许跳过 TLS 校验）。site / console 两端点共用此凭证。
 */
class RatpanelProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'ratpanel';
    }

    public function label(): string
    {
        return '耗子面板';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true],
            ['key' => 'access_token_id', 'label' => '访问令牌 ID', 'required' => true],
            ['key' => 'access_token', 'label' => '访问令牌', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
