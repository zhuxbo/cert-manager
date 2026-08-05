<?php

namespace Plugins\CloudDeploy\Deployers\Flexcdn;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * FlexCDN（自建 CDN 面板，GoEdge 同族）凭证 schema。
 *
 * 对齐 certimate AccessConfigForFlexCDN：serverUrl + apiRole（user/admin，登录时作 type）+ accessKeyId
 * + accessKey（用 AK 换登录态 token）+ allow_insecure（自签证书自建面板常见，允许跳过 TLS 校验）。
 */
class FlexcdnProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'flexcdn';
    }

    public function label(): string
    {
        return 'FlexCDN';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'server_url', 'label' => '服务地址', 'required' => true, 'destination' => true],
            ['key' => 'api_role', 'label' => 'API 角色（user / admin）', 'required' => true],
            ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'access_key', 'label' => 'AccessKey', 'required' => true, 'secret' => true],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接（跳过 TLS 校验）', 'required' => false],
        ];
    }
}
