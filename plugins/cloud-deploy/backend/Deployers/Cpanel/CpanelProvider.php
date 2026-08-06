<?php

namespace Plugins\CloudDeploy\Deployers\Cpanel;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * cPanel（自建主机面板）凭证 schema。
 *
 * 对齐 certimate AccessConfigForCPanel：serverUrl + username + apiToken（cPanel UAPI 令牌鉴权）
 * + allow_insecure（自签证书面板常见，允许跳过 TLS 校验）。
 */
class CpanelProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'cpanel';
    }

    public function label(): string
    {
        return 'cPanel';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'username', 'label' => '用户名', 'required' => true],
            ['key' => 'api_token', 'label' => 'API 令牌', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
