<?php

namespace Plugins\CloudDeploy\Deployers\Webhook;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Webhook provider：凭证为回调地址 + 请求谓词 + 默认标头 + 默认数据（对齐 certimate AccessConfigForWebhook）。
 *
 * - url：Webhook 回调地址（http/https），必填。
 * - method：请求谓词（GET/POST/PUT/PATCH/DELETE，默认 POST），选填。
 * - headers：默认请求标头（多行 `Key: Value`），选填；deployer config 的 headers 会按 key 合并覆盖。
 * - data：默认回调数据（JSON 字符串），选填；deployer config 的 webhook_data 优先。
 * - allow_insecure：是否允许不安全连接（跳过 TLS 校验），选填。
 *
 * data 可能含证书替换变量（${CERTIMATE_DEPLOYER_PRIVATEKEY} 等），但本身非凭证；headers 可能含
 * Authorization 等鉴权信息，标 secret，脱敏体系（CredentialScrubber）兜底防泄露。
 */
class WebhookProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Webhook';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'url', 'label' => 'Webhook 回调地址', 'required' => true],
            ['key' => 'method', 'label' => '请求谓词（默认 POST）', 'required' => false],
            ['key' => 'headers', 'label' => '请求标头（多行 Key: Value）', 'required' => false, 'secret' => true],
            ['key' => 'data', 'label' => '回调数据（JSON）', 'required' => false],
            ['key' => 'allow_insecure', 'label' => '允许不安全连接', 'required' => false],
        ];
    }
}
