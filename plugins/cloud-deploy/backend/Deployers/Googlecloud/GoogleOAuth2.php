<?php

namespace Plugins\CloudDeploy\Deployers\Googlecloud;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * Google service-account OAuth2（自签 JWT 换 access_token）。
 *
 * 对齐 certimate / google.golang.org/api 的 JWTConfigFromJSON 流程（two-legged OAuth，无浏览器交互）：
 *   1. 解析 service account JSON（client_email / private_key / token_uri）。
 *   2. 用 SA 私钥对 JWT 断言做 RS256 签名（PHP openssl_sign，SHA256），claims：
 *        iss=client_email, scope=请求 scope, aud=token_uri, iat=now, exp=now+3600。
 *   3. POST token_uri，grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer + assertion=JWT，
 *      换得 access_token（Bearer）。
 *
 * 仅用 GuzzleHttp（主系统 vendor）+ PHP 原生 openssl/base64url，**不依赖 google/auth、google/apiclient**。
 * 私钥仅本地签名用、绝不外发；token 端点的错误体不含私钥。HTTP client 经注入缝，测试可 mock。
 */
class GoogleOAuth2
{
    public const SCOPE_CLOUD_PLATFORM = 'https://www.googleapis.com/auth/cloud-platform';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function __construct(private readonly ?ClientInterface $http = null) {}

    /**
     * 用 service account JSON 换取 access_token。
     *
     * @param  string  $serviceAccountJson  service account 密钥 JSON 原文
     * @return string access_token（不含 "Bearer " 前缀）
     */
    public function fetchAccessToken(string $serviceAccountJson, string $scope = self::SCOPE_CLOUD_PLATFORM): string
    {
        $sa = json_decode($serviceAccountJson, true);
        if (! is_array($sa)) {
            throw new GooglecloudApiException('InvalidCredential', 'service account 密钥不是合法 JSON');
        }

        $clientEmail = isset($sa['client_email']) ? (string) $sa['client_email'] : '';
        $privateKey = isset($sa['private_key']) ? (string) $sa['private_key'] : '';
        $tokenUri = isset($sa['token_uri']) && (string) $sa['token_uri'] !== ''
            ? (string) $sa['token_uri']
            : self::DEFAULT_TOKEN_URI;

        if ($clientEmail === '' || $privateKey === '') {
            throw new GooglecloudApiException('InvalidCredential', 'service account 密钥缺少 client_email / private_key');
        }

        $assertion = $this->buildSignedJwt($clientEmail, $privateKey, $tokenUri, $scope);

        $http = $this->http ?? new GuzzleClient(['timeout' => 30]);
        $resp = $http->request('POST', $tokenUri, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            // token 端点错误体 {error, error_description}（OAuth2 标准）—— 不含私钥
            $err = isset($json['error']) ? (string) $json['error'] : ($status > 0 ? (string) $status : 'TokenError');
            $desc = isset($json['error_description']) && (string) $json['error_description'] !== ''
                ? (string) $json['error_description']
                : 'OAuth2 换取 access_token 失败';
            throw new GooglecloudApiException($err, $desc);
        }

        $token = isset($json['access_token']) ? (string) $json['access_token'] : '';
        if ($token === '') {
            throw new GooglecloudApiException('TokenError', 'OAuth2 响应未返回 access_token');
        }

        return $token;
    }

    /**
     * 构造并签名 JWT 断言（RS256）。
     */
    private function buildSignedJwt(string $clientEmail, string $privateKey, string $audience, string $scope): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $clientEmail,
            'scope' => $scope,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64UrlEncode((string) json_encode($header)),
            $this->base64UrlEncode((string) json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok || $signature === '') {
            throw new GooglecloudApiException('InvalidCredential', 'service account 私钥签名失败（私钥无效）');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
