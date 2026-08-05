<?php

namespace Plugins\CloudDeploy\Deployers\Synologydsm;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 群晖 DSM provider。凭证含自建服务地址 server_url + 账号密码（+ 可选 2FA TOTP 密钥）。
 *
 * 对齐 certimate AccessConfigForSynologyDSM：serverUrl / username / password / totpSecret /
 * allowInsecureConnections。totp_secret 为开启二步验证账号的 TOTP 共享密钥（base32），
 * 登录时据其计算动态验证码。
 */
class SynologydsmProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'synologydsm';
    }

    public function label(): string
    {
        return '群晖 DSM';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'username', 'label' => '用户名', 'required' => true],
            ['key' => 'password', 'label' => '密码', 'required' => true, 'secret' => true],
            ['key' => 'totp_secret', 'label' => '二步验证 TOTP 密钥（选填，base32）', 'required' => false, 'secret' => true],
            ['key' => 'allow_insecure_connections', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
