<?php

namespace Plugins\CloudDeploy\Deployers\Ratpanel;

use GuzzleHttp\ClientInterface;

/**
 * 耗子面板（RatPanel / AcePanel）REST 薄客户端（自定义 HMAC-SHA256 请求签名）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/ratpanel 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/api。每个请求都按 RatPanel 签名机制计算 Authorization + X-Timestamp 头：
 *   canonicalRequest = METHOD \n canonicalPath \n queryEncoded \n sha256hex(body)
 *   stringToSign     = "HMAC-SHA256" \n timestamp \n sha256hex(canonicalRequest)
 *   signature        = hmac_sha256_hex(stringToSign, accessToken)
 *   Authorization    = "HMAC-SHA256 Credential={accessTokenId}, Signature={signature}"
 * canonicalPath 取请求绝对路径中从首个 "/api" 起的片段（与 certimate signer 一致）。
 * 签名的 body 必须是**实际发送的 JSON 字节**（任何空白/键序差异都会让 sha256 失配 → 签名错）。
 *
 * 响应体 `{msg}` —— msg=="success" 成功，否则失败。HTTP 非 2xx 或 msg≠success 归一为
 * RatpanelApiException（错误码 + msg，不含凭证）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用；
 * 签名算法另有独立单测覆盖（buildAuthHeaders 是纯函数）。
 *
 * @see https://ratpanel.github.io/advanced/api#authentication-mechanism
 */
class RatpanelRestClient
{
    /**
     * @param  string  $basePath  请求路径前缀（含 serverUrl 自身 path + "/api"，用于签名 canonicalPath）
     * @param  int  $accessTokenId  访问令牌 ID（数字，进 Credential）
     * @param  string  $accessToken  访问令牌（HMAC 密钥）
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $basePath,
        private readonly int $accessTokenId,
        private readonly string $accessToken,
    ) {}

    /**
     * 设置指定网站的 SSL 证书。
     * REF: certimate ratpanel SetWebsiteCert —— POST /website/cert {name, cert, key}
     */
    public function setWebsiteCert(string $siteName, string $certPem, string $keyPem): void
    {
        $this->call('POST', 'website/cert', [
            'name' => $siteName,
            'cert' => $certPem,
            'key' => $keyPem,
        ]);
    }

    /**
     * 设置面板控制台 SSL 证书。
     * REF: certimate ratpanel-console SetSettingCert —— POST /setting/cert {cert, key}
     */
    public function setSettingCert(string $certPem, string $keyPem): void
    {
        $this->call('POST', 'setting/cert', [
            'cert' => $certPem,
            'key' => $keyPem,
        ]);
    }

    /**
     * 计算 RatPanel 签名头（纯函数，便于独立单测验证算法）。
     *
     * @param  string  $canonicalPath  从首个 /api 起的请求路径
     * @param  string  $query  已 URL 编码的查询串（无查询时空串）
     * @param  string  $body  实际发送的请求体字节
     * @return array{Authorization:string,'X-Timestamp':string}
     */
    public static function buildAuthHeaders(
        string $method,
        string $canonicalPath,
        string $query,
        string $body,
        int $accessTokenId,
        string $accessToken,
        int $timestamp,
    ): array {
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalPath,
            $query,
            hash('sha256', $body),
        ]);

        $stringToSign = implode("\n", [
            'HMAC-SHA256',
            (string) $timestamp,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $accessToken);

        return [
            'Authorization' => "HMAC-SHA256 Credential=$accessTokenId, Signature=$signature",
            'X-Timestamp' => (string) $timestamp,
        ];
    }

    /**
     * 发起带签名的 JSON 请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但 msg≠success」。
     *
     * @param  array<string,mixed>  $body
     */
    private function call(string $method, string $relPath, array $body): void
    {
        // 签名的 body 必须等于实际发送的字节：先固定序列化，再同时用于签名与发送
        $bodyJson = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $canonicalPath = $this->canonicalPath($relPath);
        $headers = self::buildAuthHeaders($method, $canonicalPath, '', $bodyJson, $this->accessTokenId, $this->accessToken, time());

        $resp = $this->http->request($method, $relPath, [
            'body' => $bodyJson,
            'headers' => $headers + ['Content-Type' => 'application/json'],
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];
        $msg = is_string($json['msg'] ?? null) ? $json['msg'] : '';

        // HTTP 非 2xx：用响应体 msg（若有）+ HTTP 状态码作错误码
        if ($status < 200 || $status >= 300) {
            throw new RatpanelApiException((string) $status, $msg !== '' ? $msg : "耗子面板接口返回 HTTP $status");
        }

        // 有响应体但 msg≠success：业务错误（与 certimate doRequestWithResult 一致）
        if ($json !== [] && $msg !== 'success') {
            throw new RatpanelApiException('RatPanelError', $msg !== '' ? $msg : '耗子面板接口返回错误');
        }
    }

    /**
     * 计算签名用 canonicalPath：basePath + "/" + relPath，再取从首个 /api 起的片段（对齐 certimate signer）。
     */
    private function canonicalPath(string $relPath): string
    {
        $path = rtrim($this->basePath, '/').'/'.ltrim($relPath, '/');
        if (! str_starts_with($path, '/api')) {
            $index = strpos($path, '/api');
            if ($index !== false) {
                $path = substr($path, $index);
            }
        }

        return $path;
    }
}
