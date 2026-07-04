<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * Azure Key Vault certificates REST 薄客户端。
 *
 * 无轻量官方 PHP SDK，照 certimate azure-keyvault 用 GuzzleHttp（来自主系统 vendor）直调 REST。
 * 鉴权：OAuth2 Bearer（access_token 由 AzureOAuth2 client_credentials 换取，注入 Authorization 请求头）。
 * vaultBaseUrl 形如 https://{vaultName}.{vaultDnsSuffix}（按主权云环境派生）。
 *
 * 仅实现「导入证书」：
 *   POST {vaultBaseUrl}/certificates/{name}/import?api-version=7.4
 *   body: {value: base64(PKCS12), policy: {secret_props: {contentType: "application/x-pkcs12"}}, tags}
 * 返回证书标识 id（kid）。
 *
 * 关于 PKCS12：Azure Key Vault 不支持导入带链的 PEM（见 certimate 注释 / Azure/azure-cli#19017），
 * 必须先 PEM→PFX。本客户端只负责传输，PEM→PFX 转换在 uploader 用 PHP 原生 openssl_pkcs12_export 完成。
 *
 * HTTP 非 2xx 归一为 AzureApiException（error.code + error.message 取自响应体，不含 token/secret）。
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class AzureKeyVaultClient
{
    private const API_VERSION = '7.4';

    private readonly ClientInterface $http;

    /**
     * @param  string  $accessToken  OAuth2 Bearer access_token（由 AzureOAuth2 提供）
     * @param  string  $vaultBaseUrl  Key Vault 基地址 https://{vault}.{dnsSuffix}
     * @param  ClientInterface|null  $http  注入缝（测试用）
     */
    public function __construct(string $accessToken, string $vaultBaseUrl, ?ClientInterface $http = null)
    {
        $this->http = $http ?? new GuzzleClient([
            'base_uri' => rtrim($vaultBaseUrl, '/').'/',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * 导入证书（PKCS12），返回证书标识 id（kid）。
     * REF: https://learn.microsoft.com/en-us/rest/api/keyvault/certificates/import-certificate/import-certificate
     *
     * @param  string  $pkcs12Base64  base64 编码的 PKCS12 字节
     * @param  array<string,string>  $tags
     */
    public function importCertificate(string $name, string $pkcs12Base64, array $tags = []): string
    {
        $body = [
            'value' => $pkcs12Base64,
            'policy' => [
                'secret_props' => [
                    'contentType' => 'application/x-pkcs12',
                ],
            ],
        ];
        if ($tags !== []) {
            $body['tags'] = $tags;
        }

        $json = $this->request('POST', 'certificates/'.rawurlencode($name).'/import', $body);
        $id = $json['id'] ?? null;

        return is_string($id) ? $id : '';
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        $resp = $this->http->request($method, $path, [
            'json' => $body,
            'query' => ['api-version' => self::API_VERSION],
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        // Azure 错误体：{error: {code, message}}
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = isset($error['code']) && (string) $error['code'] !== ''
            ? (string) $error['code']
            : ($status > 0 ? (string) $status : 'AzureError');
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "Azure 接口返回 HTTP $status";

        throw new AzureApiException($code, $message);
    }
}
