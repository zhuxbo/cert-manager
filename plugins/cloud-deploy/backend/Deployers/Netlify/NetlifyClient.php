<?php

namespace Plugins\CloudDeploy\Deployers\Netlify;

use GuzzleHttp\ClientInterface;

/**
 * Netlify REST 薄客户端（站点 SNI 证书）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/netlify 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Bearer Token（Authorization 请求头）。base：https://api.netlify.com/api/v1。
 * Netlify 失败响应体形如 `{code, message}` —— HTTP 非 2xx 或响应体带非零 code 归一为
 * NetlifyApiException（错误码 + 描述取自响应体，不含 token）。
 *
 * 注意：证书材料（certificate / ca_certificates / key）经**查询参**传递（对齐 certimate 的
 * SetQueryParams，而非请求体），故 Guzzle 用 query 选项。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class NetlifyClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 为站点配置 TLS 证书。
     * REF: certimate netlify ProvisionSiteTLSCertificate —— POST /api/v1/sites/{siteId}/ssl
     * （certificate / ca_certificates / key 走查询参）
     *
     * @param  array<string,string>  $params  certificate / ca_certificates / key
     */
    public function provisionSiteTlsCertificate(string $siteId, array $params): void
    {
        // 相对路径（无前导 /）：与 base_uri ".../api/v1/" 合并。siteId 编码进路径段。
        $this->request('POST', 'sites/'.rawurlencode($siteId).'/ssl', $params);
    }

    /**
     * 发起请求并归一错误。证书材料走 query；http_errors=false 自行判状态。
     *
     * @param  array<string,string>  $query
     */
    private function request(string $method, string $path, array $query): void
    {
        $resp = $this->http->request($method, $path, [
            'query' => $query,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $errorCode = $json['code'] ?? null;
        if ($status >= 200 && $status < 300 && ($errorCode === null || (int) $errorCode === 0)) {
            return;
        }

        // 优先取响应体 code/message（Netlify 标准错误体，安全：无凭证）
        $code = $errorCode !== null && (int) $errorCode !== 0
            ? (string) $errorCode
            : ($status > 0 ? (string) $status : 'NetlifyError');
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "Netlify 接口返回 HTTP $status";

        throw new NetlifyApiException($code, $message);
    }
}
