<?php

namespace Plugins\CloudDeploy\Deployers\Goedge;

use GuzzleHttp\ClientInterface;

/**
 * GoEdge REST 薄客户端（登录态 token 鉴权 + 更新 SSL 证书）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/goedge 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}。鉴权（关键差异）：用 accessKeyId/accessKey 登录换 token，token 进
 * `X-Edge-Access-Token` 头。token 带过期时间缓存（expiresAt），过期或未取过则重新登录（懒登录）。
 *
 * 登录：POST /APIAccessTokenService/getAPIAccessToken {type:<apiRole>, accessKeyId, accessKey}
 *   → data.{token, expiresAt}。
 * 更新：POST /SSLCertService/updateSSLCert（cert/key 经 base64 编码）。
 *
 * 响应体 `{code, message, data}` —— code==200 成功。HTTP 非 2xx 或 code≠200 归一为
 * GoedgeApiException（code / HTTP 状态码 + message，不含凭证）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class GoedgeRestClient
{
    /** 登录态 token（懒登录后缓存）。 */
    private string $token = '';

    /** token 过期 Unix 秒（0 表示未取）。 */
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiRole,
        private readonly string $accessKeyId,
        private readonly string $accessKey,
    ) {}

    /**
     * 更新指定 SSL 证书（cert/key base64 编码）。
     * REF: certimate goedge UpdateSSLCert —— POST /SSLCertService/updateSSLCert
     *
     * @param  list<string>  $dnsNames
     */
    public function updateSslCert(
        int $sslCertId,
        string $serverName,
        string $certPem,
        string $keyPem,
        int $timeBeginAt,
        int $timeEndAt,
        array $dnsNames,
    ): void {
        $this->call('POST', 'SSLCertService/updateSSLCert', [
            'sslCertId' => $sslCertId,
            'isOn' => true,
            'name' => 'certimate-'.(int) (microtime(true) * 1000),
            'description' => 'upload from Certimate',
            'serverName' => $serverName,
            'isCA' => false,
            'certData' => base64_encode($certPem),
            'keyData' => base64_encode($keyPem),
            'timeBeginAt' => $timeBeginAt,
            'timeEndAt' => $timeEndAt,
            'dnsNames' => $dnsNames,
            'commonNames' => $serverName !== '' ? [$serverName] : [],
        ]);
    }

    /**
     * 懒登录：未取 token 或已过期则 POST 登录换 token + expiresAt，缓存供后续调用。
     */
    private function ensureToken(): void
    {
        if ($this->token !== '' && $this->tokenExpiresAt > time()) {
            return;
        }

        $json = $this->call('POST', 'APIAccessTokenService/getAPIAccessToken', [
            'type' => $this->apiRole,
            'accessKeyId' => $this->accessKeyId,
            'accessKey' => $this->accessKey,
        ], true);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        $expiresAt = (int) ($data['expiresAt'] ?? 0);
        if ($token === '' || $expiresAt <= 0) {
            throw new GoedgeApiException('AuthError', 'GoEdge 登录失败：未取得有效 token');
        }

        $this->token = $token;
        $this->tokenExpiresAt = $expiresAt;
    }

    /**
     * 发起 JSON 请求并归一错误。业务调用前先 ensureToken() 并带 token 头；登录调用本身跳过以免递归。
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed> 解析后的响应体
     */
    private function call(string $method, string $path, array $body, bool $isLogin = false): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if (! $isLogin) {
            $this->ensureToken();
            $headers['X-Edge-Access-Token'] = $this->token;
        }

        $resp = $this->http->request($method, $path, [
            'json' => $body,
            'headers' => $headers,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];
        $msg = is_string($json['message'] ?? null) && $json['message'] !== '' ? $json['message'] : '';

        // HTTP 非 2xx：用响应体 message（若有）+ HTTP 状态码作错误码
        if ($status < 200 || $status >= 300) {
            throw new GoedgeApiException((string) $status, $msg !== '' ? $msg : "GoEdge 接口返回 HTTP $status");
        }

        // 有响应体但 code≠200：业务错误（与 certimate doRequestWithResult 一致）
        if ($json !== [] && (int) ($json['code'] ?? 0) !== 200) {
            throw new GoedgeApiException((string) ($json['code'] ?? 'GoEdgeError'), $msg !== '' ? $msg : 'GoEdge 接口返回错误');
        }

        return $json;
    }
}
