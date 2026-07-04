<?php

namespace Plugins\CloudDeploy\Deployers\Dokploy;

use GuzzleHttp\ClientInterface;

/**
 * Dokploy API REST 薄客户端（证书创建 + 去重 + 取组织 ID）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/dokploy 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/api。鉴权：X-Api-Key 请求头。响应体形如各端点 JSON（错误时含 message）——
 * HTTP 非 2xx 归一为 DokployApiException（HTTP 状态码 + 响应体 message，不含 api key）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class DokployClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 查询全部证书（去重用）。
     * REF: certimate dokploy CertificatesAll —— GET /certificates.all → [{certificateId, name, certificateData, privateKey}]
     *
     * @return list<array<string,mixed>>
     */
    public function certificatesAll(): array
    {
        $json = $this->request('GET', 'certificates.all', null);

        return is_array($json) ? array_values(array_filter($json, 'is_array')) : [];
    }

    /**
     * 获取当前账号信息（取默认组织 ID）。
     * REF: certimate dokploy UserGet —— GET /user.get → {organizationId, ...}
     *
     * @return string 组织 ID（organizationId）
     */
    public function userGetOrganizationId(): string
    {
        $json = $this->request('GET', 'user.get', null);
        $orgId = is_array($json) ? ($json['organizationId'] ?? null) : null;

        return is_string($orgId) ? $orgId : '';
    }

    /**
     * 创建证书。
     * REF: certimate dokploy CertificatesCreate —— POST /certificates.create
     *   {name, certificateData, privateKey, organizationId} → {certificateId, name}
     *
     * @param  array<string,mixed>  $body
     * @return array{certificateId:string,name:string}
     */
    public function certificatesCreate(array $body): array
    {
        $json = $this->request('POST', 'certificates.create', $body);
        $json = is_array($json) ? $json : [];

        return [
            'certificateId' => is_string($json['certificateId'] ?? null) ? $json['certificateId'] : '',
            'name' => is_string($json['name'] ?? null) ? $json['name'] : '',
        ];
    }

    /**
     * 发起请求并归一错误。GET 无 body，写请求带 JSON body。http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>|null  $body
     * @return array<mixed>|null 解析后的响应体（数组）；空体返回 null
     */
    private function request(string $method, string $path, ?array $body): ?array
    {
        $options = ['http_errors' => false];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $raw = (string) $resp->getBody();
        $json = json_decode($raw, true);
        $json = is_array($json) ? $json : null;

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        // 优先取响应体 message（Dokploy 标准错误体，安全：无凭证）
        $message = is_array($json) && is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "Dokploy 接口返回 HTTP $status";

        throw new DokployApiException((string) $status, $message);
    }
}
