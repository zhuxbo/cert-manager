<?php

namespace Plugins\CloudDeploy\Deployers\Nginxproxymanager;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Nginx Proxy Manager（NPM，自建反代面板）provider。
 *
 * 对齐 certimate AccessConfigForNginxProxyManager：serverUrl / authMethod / username / password /
 * apiToken / allowInsecureConnections。鉴权两种方式（auth_method）：
 *   - password（默认）：用 username + password 登录 POST /tokens 拿 JWT。
 *   - token：直接用 api_token（已有 JWT）。
 */
class NginxproxymanagerProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'nginxproxymanager';
    }

    public function label(): string
    {
        return 'Nginx Proxy Manager';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'auth_method', 'label' => '认证方式（password 账号密码 / token API Token）', 'required' => false],
            ['key' => 'username', 'label' => '用户名（认证方式为 password 时必填）', 'required' => false],
            ['key' => 'password', 'label' => '密码（认证方式为 password 时必填）', 'required' => false, 'secret' => true],
            ['key' => 'api_token', 'label' => 'API Token（认证方式为 token 时必填）', 'required' => false, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
