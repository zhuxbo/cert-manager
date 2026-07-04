<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use GuzzleHttp\ClientInterface;

/**
 * 又拍云控制台 REST 薄客户端（SSL 证书上传 + CDN/存储域名 HTTPS 管理）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/upyun/console 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：https://console.upyun.com。
 *
 * 鉴权（关键差异于其他 provider）：又拍云控制台**无 AK/SK**，走账号登录拿 Cookie——
 *   POST /accounts/signin/ {username, password} → 响应 Set-Cookie 头；后续业务接口带该 Cookie。
 * 本类用 Guzzle 注入的 CookieJar 自动保存/回送 Cookie（makeClient 构造时 `cookies` 配置已开），
 * `ensureSignedIn()` 在首个业务调用前懒登录一次（成功后 jar 持有 Cookie，无需重复登录）。
 *
 * 响应体均形如 `{data:{error_code, message, result|status}}` —— HTTP 非 2xx、无 data、或
 * data.error_code≠0 归一为 UpyunApiException（错误码 + 描述取自响应体，不含 username/password）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部
 * REST 调用，无需打真实 HTTP。
 */
class UpyunRestClient
{
    private bool $signedIn = false;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /**
     * 上传 HTTPS 证书到又拍云控制台证书库。
     * REF: certimate console api_upload_https_certificate —— POST /api/https/certificate/
     *   {certificate, private_key} → data.result.certificate_id
     *
     * @return string 云端证书 certificate_id
     */
    public function uploadHttpsCertificate(string $certificate, string $privateKey): string
    {
        $json = $this->call('POST', '/api/https/certificate/', [
            'certificate' => $certificate,
            'private_key' => $privateKey,
        ]);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $certId = $result['certificate_id'] ?? null;

        return is_string($certId) ? $certId : '';
    }

    /**
     * 查询域名的 HTTPS 服务配置（含已绑定证书列表）。
     * REF: certimate console api_get_https_service_manager —— GET /api/https/services/manager?domain=
     *   → data.result[] 每项 {certificate_id, commonName, https, force_https, ...}
     *
     * @return list<array{certificate_id?:string,https?:bool,force_https?:bool}> 域名 HTTPS 配置列表
     */
    public function getHttpsServiceManager(string $domain): array
    {
        $json = $this->call('GET', '/api/https/services/manager', null, ['domain' => $domain]);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $list = is_array($data['result'] ?? null) ? $data['result'] : [];

        // 仅保留数组型条目（防响应异常结构），键名保持原样供 deployer 读 https/certificate_id
        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * 为未启用 HTTPS 的域名启用 HTTPS 并绑定证书。
     * REF: certimate console api_update_https_certificate_manager —— POST /api/https/certificate/manager
     *   {certificate_id, domain, https, force_https}
     */
    public function updateHttpsCertificateManager(string $certificateId, string $domain, bool $https, bool $forceHttps): void
    {
        $this->call('POST', '/api/https/certificate/manager', [
            'certificate_id' => $certificateId,
            'domain' => $domain,
            'https' => $https,
            'force_https' => $forceHttps,
        ]);
    }

    /**
     * 为已启用 HTTPS 的域名迁移（更换）证书。
     * REF: certimate console api_migrate_https_domain —— POST /api/https/migrate/domain
     *   {crt_id, domain_name}（注意字段名与 update 接口不同：crt_id / domain_name）
     */
    public function migrateHttpsDomain(string $certificateId, string $domain): void
    {
        $this->call('POST', '/api/https/migrate/domain', [
            'crt_id' => $certificateId,
            'domain_name' => $domain,
        ]);
    }

    /**
     * 懒登录：首个业务调用前 POST /accounts/signin/ 拿 Cookie（jar 自动保存），仅执行一次。
     * REF: certimate console client.ensureCookies —— {username, password} → data.result==true + Set-Cookie
     */
    private function ensureSignedIn(): void
    {
        if ($this->signedIn) {
            return;
        }

        $json = $this->call('POST', '/accounts/signin/', [
            'username' => $this->username,
            'password' => $this->password,
        ], null, true);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        if (($data['result'] ?? null) !== true) {
            throw new UpyunApiException('AuthError', '又拍云账号登录失败');
        }

        $this->signedIn = true;
    }

    /**
     * 发起 JSON 请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但 data.error_code≠0」。
     * 业务调用（$isSignin=false）前先 ensureSignedIn()；登录调用本身 $isSignin=true 跳过以免递归。
     *
     * @param  array<string,mixed>|null  $body  JSON 请求体（GET 传 null）
     * @param  array<string,string>|null  $query  查询参数
     * @return array<string,mixed> 解析后的响应体
     */
    private function call(string $method, string $path, ?array $body, ?array $query = null, bool $isSignin = false): array
    {
        if (! $isSignin) {
            $this->ensureSignedIn();
        }

        $options = ['http_errors' => false];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($query !== null) {
            $options['query'] = $query;
        }

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $data = is_array($json['data'] ?? null) ? $json['data'] : null;

        // HTTP 非 2xx：用响应体 message（若有）+ HTTP 状态码作错误码
        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) && is_string($data['message'] ?? null) && $data['message'] !== ''
                ? $data['message']
                : "又拍云接口返回 HTTP $status";
            throw new UpyunApiException((string) $status, $msg);
        }

        // 2xx 但无 data：视为异常响应（与 certimate「received empty data」一致）
        if ($data === null) {
            throw new UpyunApiException('UpyunError', '又拍云接口返回空数据');
        }

        // 2xx 但 data.error_code≠0：业务错误（error_code 可能为字符串/数字，0 或空视为成功）
        $errCode = $data['error_code'] ?? null;
        if ($errCode !== null && (string) $errCode !== '' && (string) $errCode !== '0') {
            $msg = is_string($data['message'] ?? null) && $data['message'] !== '' ? $data['message'] : '又拍云接口返回错误';
            throw new UpyunApiException((string) $errCode, $msg);
        }

        return $json;
    }
}
