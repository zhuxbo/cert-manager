<?php

namespace Plugins\CloudDeploy\Deployers\Cloudflare;

use GuzzleHttp\ClientInterface;

/**
 * Cloudflare API v4 REST 薄客户端（自定义证书 custom_certificates）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/cloudflare 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Bearer Token（Authorization 请求头）。base：https://api.cloudflare.com/client/v4。
 * 响应体 `{success, errors:[{code,message}], result}` —— HTTP 非 2xx 或 success=false 归一为
 * CloudflareApiException（错误码 + 描述取自响应体 errors，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖
 * 全部 REST 调用，无需打真实 HTTP。
 */
class CloudflareClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 新建自定义证书。
     * REF: certimate cloudflare-ssl CustomCertificateCreate —— POST /zones/{zoneId}/custom_certificates
     *
     * @param  array<string,mixed>  $body
     */
    public function createCustomCertificate(string $zoneId, array $body): void
    {
        // 相对路径（无前导 /）：与 base_uri ".../client/v4/" 合并，避免绝对路径替换 base path
        $this->request('POST', "zones/$zoneId/custom_certificates", $body);
    }

    /**
     * 编辑已有自定义证书。
     * REF: certimate cloudflare-ssl CustomCertificateEdit —— PATCH /zones/{zoneId}/custom_certificates/{certId}
     *
     * @param  array<string,mixed>  $body
     */
    public function editCustomCertificate(string $zoneId, string $certId, array $body): void
    {
        $this->request('PATCH', "zones/$zoneId/custom_certificates/$certId", $body);
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但 success=false」。
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
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $success = $json['success'] ?? null;
        if ($status >= 200 && $status < 300 && $success === true) {
            return;
        }

        // 优先取响应体 errors[0]（Cloudflare 标准错误体，安全：code+message 无凭证）
        $errors = is_array($json['errors'] ?? null) ? $json['errors'] : [];
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $code = isset($first['code']) ? (string) $first['code'] : ($status > 0 ? (string) $status : 'CloudflareError');
        $message = is_string($first['message'] ?? null) && $first['message'] !== ''
            ? $first['message']
            : "Cloudflare 接口返回 HTTP $status";

        throw new CloudflareApiException($code, $message);
    }
}
