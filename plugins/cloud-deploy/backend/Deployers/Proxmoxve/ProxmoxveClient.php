<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxve;

use GuzzleHttp\ClientInterface;

/**
 * Proxmox VE API REST 薄客户端（节点自定义证书上传）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/proxmoxve 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/api2/json。鉴权：Authorization: PVEAPIToken={tokenId}={tokenSecret} 请求头。
 * 响应体形如 `{data}` —— HTTP 非 2xx 归一为 ProxmoxveApiException（HTTP 状态码 + 安全描述，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class ProxmoxveClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 上传节点自定义证书。
     * REF: certimate proxmoxve NodeUploadCustomCertificate ——
     *   POST /nodes/{node}/certificates/custom {certificates, key, force, restart}
     *
     * @param  array<string,mixed>  $body
     */
    public function nodeUploadCustomCertificate(string $node, array $body): void
    {
        $this->request('POST', 'nodes/'.rawurlencode($node).'/certificates/custom', $body);
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

        // Proxmox VE 错误体可能含 errors（逐字段校验，可能回显入参）——不取其内容，仅给 HTTP 状态码
        // + reason phrase（标准短语，无凭证），避免把请求体/凭证带进错误文案。
        $reason = $resp->getReasonPhrase();
        $message = $reason !== '' ? $reason : "Proxmox VE 接口返回 HTTP $status";

        throw new ProxmoxveApiException((string) $status, $message);
    }
}
