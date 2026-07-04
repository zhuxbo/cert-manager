<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * Azure AD OAuth2 client_credentials（service principal 换 access_token）。
 *
 * 对齐 certimate azidentity.NewClientSecretCredential 的 two-legged 流程（无浏览器交互）：
 *   POST {login}/{tenant}/oauth2/v2.0/token
 *   body: grant_type=client_credentials, client_id, client_secret, scope={vaultScope}
 * 换得 access_token（Bearer）。login 端点与 vaultScope 按主权云环境（cloudName）选取。
 *
 * 仅用 GuzzleHttp（主系统 vendor），不依赖 azure SDK。clientSecret 仅进 token 请求 body、绝不外发到
 * 其他端点；token 端点错误体不含 secret。HTTP client 经注入缝，测试可 mock。
 */
class AzureOAuth2
{
    public function __construct(private readonly ?ClientInterface $http = null) {}

    /**
     * 用 service principal 换取 Key Vault access_token。
     *
     * @return string access_token（不含 "Bearer " 前缀）
     */
    public function fetchAccessToken(string $tenantId, string $clientId, string $clientSecret, string $cloudName = ''): string
    {
        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new AzureApiException('InvalidCredential', 'Azure 凭证缺少 tenantId / clientId / clientSecret');
        }

        $env = AzureCloudEnv::resolve($cloudName);
        $tokenUri = $env['login'].'/'.rawurlencode($tenantId).'/oauth2/v2.0/token';

        $http = $this->http ?? new GuzzleClient(['timeout' => 30]);
        $resp = $http->request('POST', $tokenUri, [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => $env['vaultScope'],
            ],
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            // AAD token 错误体 {error, error_description}（OAuth2 标准）—— 不含 secret
            $err = isset($json['error']) ? (string) $json['error'] : ($status > 0 ? (string) $status : 'TokenError');
            $desc = isset($json['error_description']) && (string) $json['error_description'] !== ''
                ? (string) $json['error_description']
                : 'AAD 换取 access_token 失败';
            // error_description 可能很长（含 trace id），截断到首行
            $desc = trim((string) strtok($desc, "\r\n"));
            throw new AzureApiException($err, $desc);
        }

        $token = isset($json['access_token']) ? (string) $json['access_token'] : '';
        if ($token === '') {
            throw new AzureApiException('TokenError', 'AAD 响应未返回 access_token');
        }

        return $token;
    }
}
