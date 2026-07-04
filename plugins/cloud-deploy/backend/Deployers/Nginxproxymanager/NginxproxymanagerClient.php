<?php

namespace Plugins\CloudDeploy\Deployers\Nginxproxymanager;

use GuzzleHttp\ClientInterface;

/**
 * Nginx Proxy Manager API REST 薄客户端（证书替换上传 + 默认站点 get/set 触发重启）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/nginxproxymanager 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/api/（Guzzle base_uri 带尾斜杠，请求路径用无前导斜杠的相对路径与之合并——
 * 避免 PSR-7 把前导斜杠路径当绝对路径替换掉 base 的 /api 段）。
 *
 * 鉴权（关键差异）：NPM 走 JWT Bearer——
 *   - 已配置 api_token：直接作 Bearer，无需登录。
 *   - 否则懒登录：首个业务调用前 POST /tokens {identity, secret} → token，jar 内缓存（仅一次）。
 * 后续业务接口带 `Authorization: Bearer {token}`。
 *
 * 响应体形如各端点 JSON；错误时 `{error: {code, message} | "string"}`——HTTP 非 2xx 或「2xx 但
 * error 非空」归一为 NginxproxymanagerApiException（错误码 + 描述取自响应体 error，不含 identity/secret/token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class NginxproxymanagerClient
{
    private string $token;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $username = '',
        private readonly string $password = '',
        string $apiToken = '',
    ) {
        $this->token = $apiToken;
    }

    /**
     * 替换指定证书内容（上传 PEM 到已有证书）。
     * REF: certimate NginxUploadCertificate ——
     *   POST /nginx/certificates/{certId}/upload（multipart: certificate / certificate_key / intermediate_certificate）
     */
    public function uploadCertificate(int $certId, string $certificate, string $certificateKey, string $intermediateCertificate): void
    {
        $this->call('POST', "nginx/certificates/$certId/upload", [
            'multipart' => [
                ['name' => 'certificate', 'contents' => $certificate, 'filename' => 'certificate.pem'],
                ['name' => 'certificate_key', 'contents' => $certificateKey, 'filename' => 'privkey.pem'],
                ['name' => 'intermediate_certificate', 'contents' => $intermediateCertificate, 'filename' => 'cabundle.pem'],
            ],
        ]);
    }

    /**
     * 获取默认站点配置（含 value）。
     * REF: certimate SettingsGetDefaultSite —— GET /settings/default-site → {value, ...}
     *
     * @return string 默认站点 value（如 congratulations / 444 / redirect / html）
     */
    public function getDefaultSiteValue(): string
    {
        $json = $this->call('GET', 'settings/default-site', []);
        $value = $json['value'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * 更新默认站点（沿用原 value），用以触发 nginx 重启。
     * REF: certimate SettingsSetDefaultSite —— PUT /settings/default-site {value}
     */
    public function setDefaultSite(string $value): void
    {
        $this->call('PUT', 'settings/default-site', ['json' => ['value' => $value]]);
    }

    /**
     * 懒登录：无 api_token 时首个业务调用前 POST /tokens 拿 JWT（仅一次）。
     * REF: certimate client.ensureToken —— {identity, secret} → token
     */
    private function ensureToken(): void
    {
        if ($this->token !== '') {
            return;
        }

        $json = $this->call('POST', 'tokens', ['json' => [
            'identity' => $this->username,
            'secret' => $this->password,
        ]], true);

        $token = $json['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new NginxproxymanagerApiException('AuthError', 'Nginx Proxy Manager 登录失败');
        }

        $this->token = $token;
    }

    /**
     * 发起请求并归一错误。业务调用（$isLogin=false）前先 ensureToken() 并带 Bearer；登录调用本身
     * $isLogin=true 跳过以免递归。http_errors=false 自行判状态，兼容「2xx 但 error 非空」。
     *
     * @param  array<string,mixed>  $options  Guzzle 选项（json / multipart）
     * @return array<string,mixed> 解析后的响应体
     */
    private function call(string $method, string $path, array $options, bool $isLogin = false): array
    {
        if (! $isLogin) {
            $this->ensureToken();
            $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer '.$this->token];
        }
        $options['http_errors'] = false;

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $error = $this->extractError($json['error'] ?? null);

        // HTTP 非 2xx：优先用响应体 error，否则 HTTP 状态码
        if ($status < 200 || $status >= 300) {
            throw new NginxproxymanagerApiException(
                (string) $status,
                $error !== '' ? $error : "Nginx Proxy Manager 接口返回 HTTP $status",
            );
        }

        // 2xx 但 error 非空：业务错误（对齐 certimate doRequestWithResult 的 GetError 判断）
        if ($error !== '') {
            throw new NginxproxymanagerApiException('NginxProxyManagerError', $error);
        }

        return $json;
    }

    /**
     * 解析响应体 error 字段（对齐 certimate sdkResponseBase.GetError）：
     * 可能是字符串、`{code, message}` 对象、或 `{message}` map；提取人类可读描述（无凭证）。
     */
    private function extractError(mixed $error): string
    {
        if ($error === null) {
            return '';
        }
        if (is_string($error)) {
            return $error;
        }
        if (is_array($error)) {
            $message = $error['message'] ?? null;
            if (is_string($message) && $message !== '') {
                $code = $error['code'] ?? null;

                return (is_int($code) || (is_string($code) && $code !== '')) && (string) $code !== '0'
                    ? "$code $message"
                    : $message;
            }
        }

        return '';
    }
}
