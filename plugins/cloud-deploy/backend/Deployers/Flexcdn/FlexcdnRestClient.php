<?php

namespace Plugins\CloudDeploy\Deployers\Flexcdn;

use GuzzleHttp\ClientInterface;

/**
 * FlexCDN REST 薄客户端（登录态 token 鉴权 + 更新 SSL 证书）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/flexcdn 用 GuzzleHttp（来自主系统 vendor）直调。
 * 与 GoEdge 同族、流程一致，**唯一差异是 token 头名 `X-Cloud-Access-Token`**（GoEdge 用 X-Edge-Access-Token）。
 * base：{serverUrl}。用 accessKeyId/accessKey 登录换 token（带 expiresAt 缓存，过期重登）。
 *
 * 登录：POST /APIAccessTokenService/getAPIAccessToken {type:<apiRole>, accessKeyId, accessKey} → data.{token, expiresAt}。
 * 更新：POST /SSLCertService/updateSSLCert（cert/key 经 base64 编码）。
 *
 * 响应体 `{code, message, data}` —— code==200 成功。HTTP 非 2xx 或 code≠200 归一为 FlexcdnApiException。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class FlexcdnRestClient
{
    private string $token = '';

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiRole,
        private readonly string $accessKeyId,
        private readonly string $accessKey,
    ) {}

    /**
     * 更新指定 SSL 证书（cert/key base64 编码）。
     * REF: certimate flexcdn UpdateSSLCert —— POST /SSLCertService/updateSSLCert
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
            throw new FlexcdnApiException('AuthError', 'FlexCDN 登录失败：未取得有效 token');
        }

        $this->token = $token;
        $this->tokenExpiresAt = $expiresAt;
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, array $body, bool $isLogin = false): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if (! $isLogin) {
            $this->ensureToken();
            $headers['X-Cloud-Access-Token'] = $this->token;
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

        if ($status < 200 || $status >= 300) {
            throw new FlexcdnApiException((string) $status, $msg !== '' ? $msg : "FlexCDN 接口返回 HTTP $status");
        }

        if ($json !== [] && (int) ($json['code'] ?? 0) !== 200) {
            throw new FlexcdnApiException((string) ($json['code'] ?? 'FlexCDNError'), $msg !== '' ? $msg : 'FlexCDN 接口返回错误');
        }

        return $json;
    }
}
