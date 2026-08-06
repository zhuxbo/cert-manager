<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanelgo;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 宝塔面板（Windows Go 版）provider：凭证为面板地址 + 接口密钥（对齐 certimate AccessConfigForBaotaPanelGo）。
 *
 * - server_url：宝塔面板服务地址，必填。
 * - api_key：宝塔面板接口密钥（api_sk），必填（本地算 MD5 签名，不出现在 URL）。
 * - allow_insecure：是否允许不安全连接（跳过 TLS 校验），选填。
 *
 * product keys：site（网站证书）+ console（面板自身 SSL）。
 */
class BaotapanelgoProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'baotapanelgo';
    }

    public function label(): string
    {
        return '宝塔面板（Windows）';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '面板地址', 'required' => true, 'destination' => true],
            ['key' => 'api_key', 'label' => '接口密钥', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接', 'required' => false],
        ];
    }
}
