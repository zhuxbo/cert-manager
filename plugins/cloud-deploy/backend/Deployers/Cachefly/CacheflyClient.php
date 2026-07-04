<?php

namespace Plugins\CloudDeploy\Deployers\Cachefly;

use GuzzleHttp\ClientInterface;

/**
 * CacheFly API v2.5 REST 薄客户端（证书 certificates）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/cachefly 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Bearer Token（X-CF-Authorization 请求头）。base：https://api.cachefly.com/api/2.5。
 * HTTP 非 2xx 归一为 CacheflyApiException（错误码取 HTTP 状态、描述取响应体 message，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖
 * 全部 REST 调用，无需打真实 HTTP。
 */
class CacheflyClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 上传证书。
     * REF: certimate cachefly CreateCertificate —— POST /certificates {certificate, certificateKey}
     *
     * @param  array<string,mixed>  $body
     */
    public function createCertificate(array $body): void
    {
        // 相对路径（无前导 /）：与 base_uri ".../api/2.5/" 合并，避免绝对路径替换 base path。
        $this->request('POST', 'certificates', $body);
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

        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        // 错误码用 HTTP 状态，描述优先取响应体 message（CacheFly 标准错误体，安全：无凭证）
        $code = $status > 0 ? (string) $status : 'CacheflyError';
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "CacheFly 接口返回 HTTP $status";

        throw new CacheflyApiException($code, $message);
    }
}
