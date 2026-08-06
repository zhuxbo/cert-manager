<?php

namespace Plugins\CloudDeploy\Deployers\Lecdn;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * LeCDN（自建 CDN 面板）凭证 schema。
 *
 * 对齐 certimate AccessConfigForLeCDN：serverUrl + apiVersion（仅 v3）+ apiRole（client 用户端 / master 主控端）
 * + username + password（账号密码登录换 Bearer token）+ allow_insecure（自签证书自建面板常见）。
 * 注意：与 GoEdge 同族不同——LeCDN 用账号密码登录、Bearer 头、base 追加 /prod-api。
 */
class LecdnProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'lecdn';
    }

    public function label(): string
    {
        return 'LeCDN';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_version', 'label' => 'API 版本（v3）', 'required' => true],
            ['key' => 'api_role', 'label' => 'API 角色（client 用户端 / master 主控端）', 'required' => true],
            ['key' => 'username', 'label' => '用户名', 'required' => true],
            ['key' => 'password', 'label' => '密码', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
