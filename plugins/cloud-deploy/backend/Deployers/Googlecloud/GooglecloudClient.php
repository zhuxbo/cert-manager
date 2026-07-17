<?php

namespace Plugins\CloudDeploy\Deployers\Googlecloud;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * Google Certificate Manager v1 REST 薄客户端。
 *
 * 无官方轻量 PHP SDK（google/apiclient 体量大），照 certimate googlecloud-certificatemanager 用
 * GuzzleHttp（来自主系统 vendor）直调 REST。鉴权：OAuth2 Bearer（access_token 由 GoogleOAuth2 自签 JWT
 * 换取，注入到 Authorization 请求头）。base：https://certificatemanager.googleapis.com/v1。
 *
 * 仅实现「创建自签 self-managed 证书」：
 *   POST /v1/projects/{project}/locations/{location}/certificates?certificateId={certId}
 *   body: {description, selfManaged: {pemCertificate, pemPrivateKey}}
 * GCP 返回一个长时操作（LRO，{name:"operations/..."}）—— 对齐 certimate，不轮询 LRO 完成，
 * 创建请求被接受即视为上传成功，返回证书资源名 projects/{p}/locations/{l}/certificates/{certId}。
 *
 * HTTP 非 2xx 归一为 GooglecloudApiException（error.status + error.message 取自响应体，不含 token/私钥）。
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class GooglecloudClient
{
    private const BASE_URI = 'https://certificatemanager.googleapis.com/v1/';

    private readonly ClientInterface $http;

    /**
     * @param  string  $accessToken  OAuth2 Bearer access_token（由 GoogleOAuth2 提供）
     * @param  ClientInterface|null  $http  注入缝（测试用）；缺省时按 base_uri + Bearer 构造 Guzzle
     */
    public function __construct(string $accessToken, ?ClientInterface $http = null)
    {
        $this->http = $http ?? new GuzzleClient([
            'base_uri' => self::BASE_URI,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * 创建自签证书，返回证书资源全名。
     * REF: https://cloud.google.com/certificate-manager/docs/reference/rest/v1/projects.locations.certificates/create
     *
     * @param  array<string,mixed>  $body  selfManaged 证书体
     * @return string 证书资源名 projects/{project}/locations/{location}/certificates/{certId}
     */
    public function createCertificate(string $project, string $location, string $certId, array $body): string
    {
        $path = "projects/$project/locations/$location/certificates";
        $this->request('POST', $path, $body, ['certificateId' => $certId]);

        return "projects/$project/locations/$location/certificates/$certId";
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,string>  $query
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $body, array $query = []): array
    {
        $resp = $this->http->request($method, $path, [
            'json' => $body,
            'query' => $query,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        // GCP 错误体：{error: {code, status, message}}
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = isset($error['status']) && (string) $error['status'] !== ''
            ? (string) $error['status']
            : ($status > 0 ? (string) $status : 'GoogleCloudError');
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "Google Cloud 接口返回 HTTP $status";

        throw new GooglecloudApiException($code, $message);
    }
}
