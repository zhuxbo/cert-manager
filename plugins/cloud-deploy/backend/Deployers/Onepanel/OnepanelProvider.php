<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 1Panel provider：凭证为面板地址 + 版本 + 接口密钥（对齐 certimate AccessConfigFor1Panel）。
 *
 * provider key 为 'onepanel'（类/目录不能数字开头），但对应 certimate 的 1panel；接线 product key
 * 见各 deployer。
 *
 * - server_url：1Panel 服务地址（如 https://panel.example.com:8888），必填。
 * - api_version：API 版本，v1 / v2（默认 v1），必填。
 * - api_key：1Panel 接口密钥，必填（本地算 MD5 签名，不出现在 URL）。
 * - node_name：子节点名称（仅 v2 有意义，默认 local），选填。
 * - allow_insecure：是否允许不安全连接（跳过 TLS 校验），选填。
 */
class OnepanelProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'onepanel';
    }

    public function label(): string
    {
        return '1Panel';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '面板地址', 'required' => true, 'destination' => true],
            ['key' => 'api_version', 'label' => 'API 版本（v1/v2）', 'required' => true],
            ['key' => 'api_key', 'label' => '接口密钥', 'required' => true, 'secret' => true],
            ['key' => 'node_name', 'label' => '子节点名称（仅 v2，默认 local）', 'required' => false],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接', 'required' => false],
        ];
    }
}
