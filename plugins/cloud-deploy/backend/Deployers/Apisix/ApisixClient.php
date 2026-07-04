<?php

namespace Plugins\CloudDeploy\Deployers\Apisix;

use GuzzleHttp\ClientInterface;

/**
 * APISIX Admin API REST 薄客户端（SSL 证书更新）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/apisix 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/apisix/admin。鉴权：X-API-KEY 请求头。响应体形如 `{...}`（成功）/
 * `{error_msg}`（错误）—— HTTP 非 2xx 归一为 ApisixApiException（HTTP 状态码 + 响应体 error_msg，
 * 不含 api key）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class ApisixClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 更新（按 id 创建或替换）SSL 证书。
     * REF: certimate apisix UpdateSSL —— PUT /ssls/{sslId} {id, cert, key, snis, type, status}
     *
     * @param  array<string,mixed>  $body
     */
    public function updateSsl(string $sslId, array $body): void
    {
        $this->request('PUT', 'ssls/'.rawurlencode($sslId), $body);
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

        // 优先取响应体 error_msg（APISIX 标准错误体，安全：无凭证）
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];
        $message = is_string($json['error_msg'] ?? null) && $json['error_msg'] !== ''
            ? $json['error_msg']
            : "APISIX 接口返回 HTTP $status";

        throw new ApisixApiException((string) $status, $message);
    }
}
