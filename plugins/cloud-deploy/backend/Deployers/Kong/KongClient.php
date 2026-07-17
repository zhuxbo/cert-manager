<?php

namespace Plugins\CloudDeploy\Deployers\Kong;

use GuzzleHttp\ClientInterface;

/**
 * Kong Admin API REST 薄客户端（证书 upsert）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/kong 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}（GuzzleClient base_uri）。workspace 选填——certimate 把它拼到 base path 前缀
 * （`/{workspace}/certificates/{id}`），本类在请求路径前加 workspace 段以对齐（空则不拼）。
 * 鉴权：Kong-Admin-Token 请求头。响应体形如 `{...}`（成功）/ `{message}`（错误）——
 * HTTP 非 2xx 归一为 KongApiException（HTTP 状态码 + 响应体 message，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class KongClient
{
    /** @param string $workspace Kong 工作空间（选填，拼到请求路径前缀）。 */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $workspace = '',
    ) {}

    /**
     * Upsert（按 id 创建或替换）证书。
     * REF: certimate kong UpsertCertificate —— PUT /certificates/{certificateId} {id, cert, key, snis}
     *
     * @param  array<string,mixed>  $body
     */
    public function upsertCertificate(string $certificateId, array $body): void
    {
        $this->request('PUT', $this->prefix('certificates/'.rawurlencode($certificateId)), $body);
    }

    /** 拼上 workspace 前缀（对齐 certimate base path：`/{workspace}/...`）。 */
    private function prefix(string $path): string
    {
        return $this->workspace !== ''
            ? rawurlencode($this->workspace).'/'.$path
            : $path;
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>  $body
     */
    private function request(string $method, string $path, array $body): void
    {
        $resp = $this->http->request($method, $path, [
            'json' => $body,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        // 优先取响应体 message（Kong 标准错误体，安全：无凭证）
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "Kong 接口返回 HTTP $status";

        throw new KongApiException((string) $status, $message);
    }
}
