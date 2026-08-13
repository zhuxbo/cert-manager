<?php

namespace Plugins\CloudDeploy\Deployers\Synologydsm;

use GuzzleHttp\ClientInterface;

/**
 * 群晖 DSM WebAPI REST 薄客户端（登录 + 证书导入 + 证书列表）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/synologydsm 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}。
 *
 * 鉴权（关键差异）：群晖 DSM 走「登录拿 sid + SynoToken」——
 *   1. 懒查询 API 信息：GET /webapi/query.cgi?api=SYNO.API.Info&method=query&version=1&query=SYNO.API.Auth
 *      → 得 SYNO.API.Auth 的 path + maxVersion。
 *   2. 登录：GET /webapi/{authPath}?api=SYNO.API.Auth&method=login&format=sid&enable_syno_token=yes
 *      &account=&passwd=&otp_code= → {sid, synotoken}。
 *   3. 后续业务接口带 _sid/SynoToken 查询串 + X-SYNO-TOKEN 头。
 *
 * 响应体形如 `{success, error:{code}, data}`——HTTP 非 2xx 或 success=false 归一为
 * SynologydsmApiException（错误码 + 可读描述，不含账号/密码/sid/token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class SynologydsmClient
{
    private string $authPath = '';

    private int $authVersion = 0;

    private string $sid = '';

    private string $synoToken = '';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $totpSecret = '',
    ) {}

    /**
     * 登录（懒查询 API 信息 → login）。已登录则跳过。
     * 启用 TOTP（totp_secret 非空）时据密钥计算当前动态验证码作 otp_code。
     */
    public function login(): void
    {
        if ($this->sid !== '') {
            return;
        }

        $this->ensureAuthApiInfo();

        $otpCode = $this->totpSecret !== '' ? $this->generateTotp($this->totpSecret) : '';

        $query = [
            'api' => 'SYNO.API.Auth',
            'version' => (string) $this->authVersion,
            'method' => 'login',
            'format' => 'sid',
            'enable_syno_token' => 'yes',
            'account' => $this->username,
            'passwd' => $this->password,
        ];
        if ($otpCode !== '') {
            $query['otp_code'] = $otpCode;
        }

        $json = $this->get("/webapi/$this->authPath", $query);
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $sid = $data['sid'] ?? null;
        $synoToken = $data['synotoken'] ?? null;
        if (! is_string($sid) || $sid === '' || ! is_string($synoToken) || $synoToken === '') {
            throw new SynologydsmApiException('AuthError', '群晖 DSM 登录成功但未返回 sid 或 synotoken');
        }

        $this->sid = $sid;
        $this->synoToken = $synoToken;
    }

    /** 登出（释放会话）。未登录则空操作。失败静默（不影响主流程）。 */
    public function logout(): void
    {
        if ($this->sid === '') {
            return;
        }

        try {
            $this->get("/webapi/$this->authPath", [
                'api' => 'SYNO.API.Auth',
                'version' => (string) $this->authVersion,
                'method' => 'logout',
                '_sid' => $this->sid,
            ]);
        } catch (SynologydsmApiException) {
            // 登出失败不影响已完成的证书导入，静默
        }

        $this->sid = '';
        $this->synoToken = '';
    }

    /**
     * 列出全部证书（用于按 id/描述匹配已有证书）。
     * REF: certimate ListCertificates —— SYNO.Core.Certificate.CRT:list
     *
     * @return list<array<string,mixed>> 每项含 id / desc / is_default / services
     */
    public function listCertificates(): array
    {
        $json = $this->get('/webapi/entry.cgi', [
            'api' => 'SYNO.Core.Certificate.CRT',
            'method' => 'list',
            'version' => '1',
            '_sid' => $this->sid,
            'SynoToken' => $this->synoToken,
        ]);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $certs = is_array($data['certificates'] ?? null) ? $data['certificates'] : [];

        return array_values(array_filter($certs, 'is_array'));
    }

    /**
     * 导入证书（新建 id 留空，更新传已有 id）。multipart 上传 key/cert/inter_cert + 元信息。
     * REF: certimate ImportCertificate —— SYNO.Core.Certificate:import
     */
    public function importCertificate(string $id, string $description, string $key, string $cert, string $interCert, bool $asDefault): void
    {
        $multipart = [
            ['name' => 'key', 'contents' => $key, 'filename' => 'key.pem'],
            ['name' => 'cert', 'contents' => $cert, 'filename' => 'cert.pem'],
            ['name' => 'inter_cert', 'contents' => $interCert, 'filename' => 'chain.pem'],
            ['name' => 'id', 'contents' => $id],
            ['name' => 'desc', 'contents' => $description],
        ];
        if ($asDefault) {
            $multipart[] = ['name' => 'as_default', 'contents' => 'true'];
        }

        $this->post('/webapi/entry.cgi', [
            'api' => 'SYNO.Core.Certificate',
            'method' => 'import',
            'version' => '1',
            '_sid' => $this->sid,
            'SynoToken' => $this->synoToken,
        ], ['multipart' => $multipart]);
    }

    /**
     * 把各服务从旧证书迁移到新默认证书并触发相关服务重载。
     *
     * @param  list<array{service:array<string,mixed>,old_id:string,id:string}>  $settings
     */
    public function setServiceCertificates(array $settings): void
    {
        $this->post('/webapi/entry.cgi', [
            'api' => 'SYNO.Core.Certificate.Service',
            'method' => 'set',
            'version' => '1',
            '_sid' => $this->sid,
            'SynoToken' => $this->synoToken,
        ], [
            'form_params' => [
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ]);
    }

    /** 懒查询 SYNO.API.Auth 的 path + maxVersion（仅一次）。 */
    private function ensureAuthApiInfo(): void
    {
        if ($this->authPath !== '' && $this->authVersion !== 0) {
            return;
        }

        $json = $this->get('/webapi/query.cgi', [
            'api' => 'SYNO.API.Info',
            'version' => '1',
            'method' => 'query',
            'query' => 'SYNO.API.Auth',
        ]);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $authInfo = is_array($data['SYNO.API.Auth'] ?? null) ? $data['SYNO.API.Auth'] : null;
        if ($authInfo === null) {
            throw new SynologydsmApiException('AuthError', '群晖 DSM 未找到 SYNO.API.Auth 接口信息');
        }

        $path = $authInfo['path'] ?? null;
        $maxVersion = $authInfo['maxVersion'] ?? null;
        $this->authPath = is_string($path) && $path !== '' ? $path : 'auth.cgi';
        $this->authVersion = is_int($maxVersion) && $maxVersion > 0 ? $maxVersion : 6;
    }

    /**
     * GET 请求 + 归一错误。
     *
     * @param  array<string,string>  $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query): array
    {
        return $this->call('GET', $path, ['query' => $query]);
    }

    /**
     * POST 请求 + 归一错误。
     *
     * @param  array<string,string>  $query
     * @param  array<string,mixed>  $options  额外 Guzzle 选项（multipart）
     * @return array<string,mixed>
     */
    private function post(string $path, array $query, array $options): array
    {
        return $this->call('POST', $path, ['query' => $query] + $options);
    }

    /**
     * 发起请求并归一错误。带 X-SYNO-TOKEN 头（登录后）。http_errors=false 自行判状态，
     * 兼容「2xx 但 success=false」。
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, array $options): array
    {
        if ($this->synoToken !== '') {
            $options['headers'] = ($options['headers'] ?? []) + ['X-SYNO-TOKEN' => $this->synoToken];
        }
        $options['http_errors'] = false;

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            throw new SynologydsmApiException((string) $status, "群晖 DSM 接口返回 HTTP $status");
        }

        // 2xx 但 success=false：业务错误（error.code → 可读描述，无入参回显）
        if (($json['success'] ?? null) !== true) {
            $code = is_array($json['error'] ?? null) ? ($json['error']['code'] ?? null) : null;
            $codeInt = is_int($code) ? $code : (is_numeric($code) ? (int) $code : 0);
            throw new SynologydsmApiException(
                $codeInt !== 0 ? (string) $codeInt : 'SynologyDSMError',
                $this->describeError($codeInt),
            );
        }

        return $json;
    }

    /** 群晖认证/通用错误码 → 可读描述（对齐 certimate getAuthErrorDescription，无凭证）。 */
    private function describeError(int $code): string
    {
        return match ($code) {
            100 => 'Unknown error',
            101 => 'Invalid parameters',
            102 => 'API does not exist',
            103 => 'Method does not exist',
            104 => 'This API version is not supported',
            105 => 'Insufficient user privilege',
            106 => 'Connection time out',
            107 => 'Multiple login detected',
            400 => 'Invalid password or account does not exist',
            401 => 'Guest or disabled account',
            402 => 'Permission denied',
            403 => '2-factor authentication code required (OTP)',
            404 => 'Failed to authenticate 2-factor authentication code',
            405 => 'Server version is too low or not supported',
            406 => '2-factor authentication code expired',
            407 => 'Login failed: IP has been blocked',
            408 => 'Expired password',
            409 => 'Password must be changed (password policy)',
            default => "群晖 DSM 接口返回错误码 $code",
        };
    }

    /**
     * 计算 TOTP 动态验证码（RFC 6238，30s 步长 / 6 位 / HMAC-SHA1），纯 PHP 实现（不引第三方库）。
     * $secret 为 base32 编码的共享密钥。
     */
    private function generateTotp(string $secret): string
    {
        $key = $this->base32Decode($secret);
        $counter = intdiv(time(), 30);
        $binCounter = pack('N*', 0).pack('N*', $counter); // 8 字节大端计数器
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** base32 解码（RFC 4648，大写，忽略填充与空白），用于 TOTP 密钥。 */
    private function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr((int) bindec($byte));
            }
        }

        return $bytes;
    }
}
