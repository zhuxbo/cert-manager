<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 网宿科技（Wangsu / ChinaNetCenter）provider。
 *
 * 凭证对齐 certimate domain.AccessConfigForWangsu：
 *   accessKeyId / accessKeySecret / apiKey。
 * 其中 apiKey 仅 CDN Pro 端点使用（本地 AES 加密私钥派生），cdn / certificate 端点只需 ak/sk。
 * 三端点共用同一套凭证（apiKey 选填，未填时 cdn/certificate 可用、cdnpro 会因缺 apiKey 报错）。
 */
class WangsuProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'wangsu';
    }

    public function label(): string
    {
        return '网宿科技';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKeyId', 'required' => true],
            ['key' => 'access_key_secret', 'label' => 'AccessKeySecret', 'required' => true, 'secret' => true],
            ['key' => 'api_key', 'label' => 'API Key（仅 CDN Pro 需要）', 'required' => false, 'secret' => true],
        ];
    }
}
