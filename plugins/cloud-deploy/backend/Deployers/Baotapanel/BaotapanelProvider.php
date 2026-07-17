<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanel;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 宝塔面板 provider：凭证为面板地址 + 接口密钥（对齐 certimate AccessConfigForBaotaPanel）。
 *
 * - server_url：宝塔面板服务地址（如 https://panel.example.com:8888），必填。
 * - api_key：宝塔面板接口密钥（api_sk），必填（本地算 MD5 签名，不出现在 URL）。
 * - allow_insecure：是否允许不安全连接（跳过 TLS 校验），选填。
 *
 * product keys：site（网站证书）+ console（面板自身 SSL）。
 */
class BaotapanelProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'baotapanel';
    }

    public function label(): string
    {
        return '宝塔面板';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '面板地址', 'required' => true],
            ['key' => 'api_key', 'label' => '接口密钥', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接', 'required' => false],
        ];
    }
}
