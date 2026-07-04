<?php

namespace Plugins\CloudDeploy\Deployers\Digitalocean;

use GuzzleHttp\ClientInterface;

/**
 * DigitalOcean API v2 REST 薄客户端（证书 certificates）。
 *
 * 无需官方 SDK，照 certimate pkg/sdk3rd/digitalocean 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Bearer Token。base：https://api.digitalocean.com/v2。HTTP 非 2xx 归一为
 * DigitaloceanApiException（错误 id+message 取自响应体，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class DigitaloceanClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 上传自定义证书。
     * REF: certimate digitalocean-certificate CreateCertificate —— POST /v2/certificates
     *
     * @param  array<string,mixed>  $body
     * @return string 云端证书 id
     */
    public function createCertificate(array $body): string
    {
        $json = $this->request('POST', 'certificates', $body);
        $cert = is_array($json['certificate'] ?? null) ? $json['certificate'] : [];
        $id = $cert['id'] ?? null;

        return is_string($id) ? $id : '';
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        $resp = $this->http->request($method, $path, [
            'json' => $body,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        // DO 错误体：{id, message}
        $code = isset($json['id']) ? (string) $json['id'] : ($status > 0 ? (string) $status : 'DigitalOceanError');
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "DigitalOcean 接口返回 HTTP $status";

        throw new DigitaloceanApiException($code, $message);
    }
}
