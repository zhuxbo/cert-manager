<?php

namespace Plugins\CloudDeploy\Deployers\Gcore;

use GuzzleHttp\ClientInterface;

/**
 * Gcore CDN REST 薄客户端（SSL 证书 + CDN 资源）。
 *
 * 无模块化官方 PHP SDK（certimate 用 Go 的 gcorelabscdn-go），照其用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Authorization: APIKey {token} 请求头（**非 Bearer**）。base：https://api.gcore.com。
 * Gcore 失败响应体形如 `{message, errors}` —— HTTP 非 2xx 归一为 GcoreApiException
 * （错误码=HTTP 状态、描述取自响应体 message/errors，不含 token）。
 *
 * 端点（对齐 gcorelabscdn-go v1.0.37 sslcerts / resources service）：
 *   - POST /cdn/sslData                上传证书（CreateRequest），返回 {id, name}
 *   - GET  /cdn/resources/{id}         取 CDN 资源详情
 *   - PUT  /cdn/resources/{id}         更新 CDN 资源（绑定 sslData）
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class GcoreClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 上传 SSL 证书。
     * REF: gcorelabscdn-go sslcerts Create —— POST /cdn/sslData
     * 字段名严格对齐 SDK：sslCertificate（证书）/ sslPrivateKey（私钥）/ name / automated / validate_root_ca。
     *
     * @param  array<string,mixed>  $body
     * @return int 云端证书 id
     */
    public function createSslData(array $body): int
    {
        $json = $this->request('POST', 'cdn/sslData', $body);
        $id = $json['id'] ?? null;

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : 0);
    }

    /**
     * 取 CDN 资源详情。
     * REF: gcorelabscdn-go resources Get —— GET /cdn/resources/{id}
     *
     * @return array<string,mixed>
     */
    public function getResource(int $resourceId): array
    {
        return $this->request('GET', "cdn/resources/$resourceId", null);
    }

    /**
     * 更新 CDN 资源（绑定证书）。
     * REF: gcorelabscdn-go resources Update —— PUT /cdn/resources/{id}
     *
     * @param  array<string,mixed>  $body
     */
    public function updateResource(int $resourceId, array $body): void
    {
        $this->request('PUT', "cdn/resources/$resourceId", $body);
    }

    /**
     * 发起请求并归一错误。http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>|null  $body  null 时不带请求体（GET）
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $options = ['http_errors' => false];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        // gcore.ErrorResponse：{message, errors}。优先 message，其次序列化 errors（安全：无凭证）
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : '';
        if ($message === '' && isset($json['errors'])) {
            $message = json_encode($json['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        if ($message === '') {
            $message = "Gcore 接口返回 HTTP $status";
        }
        $code = $status > 0 ? (string) $status : 'GcoreError';

        throw new GcoreApiException($code, $message);
    }
}
