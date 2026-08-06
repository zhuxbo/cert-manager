<?php

namespace Plugins\CloudDeploy\Deployers\Baotawaf;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 堡塔云 WAF provider：凭证为 WAF 服务地址 + 接口密钥（对齐 certimate AccessConfigForBaotaWAF）。
 *
 * - server_url：堡塔云 WAF 服务地址，必填。
 * - api_key：堡塔云 WAF 接口密钥（api_sk），必填（本地算 MD5 签名，不出现在 URL）。
 * - allow_insecure：是否允许不安全连接（跳过 TLS 校验），选填。
 *
 * product keys：site（网站证书）+ console（WAF 面板自身 SSL）。
 */
class BaotawafProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'baotawaf';
    }

    public function label(): string
    {
        return '堡塔云 WAF';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => 'WAF 服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_key', 'label' => '接口密钥', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接', 'required' => false],
        ];
    }
}
