<?php

namespace Plugins\CloudDeploy\Deployers\Mohua;

use GuzzleHttp\ClientInterface;

/**
 * 嘿华云 REST 薄客户端（虚拟主机 SSL）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/mohua 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：https://cloud.mhjz1.cn。鉴权两步：① POST /v1/login_api {account, password} → {jwt}；
 * ② 后续请求带 `JWT: Bearer {token}` 请求头。响应体 `{status, msg}` —— HTTP 非 2xx 或 status!=200 归一为
 * MohuaApiException（status + msg，不含凭证）。token 进程内缓存（首次鉴权调用时懒登录）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class MohuaClient
{
    private string $token = '';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /**
     * 设置虚拟主机 SSL 证书。
     * REF: certimate mohua-mvh SetSSL —— POST /provision/custom/{hostId}/domains
     * body {func:"SetSSL", id, ssl_force, sslCert(url-encoded), sslKey(url-encoded)}
     *
     * @param  int  $domainId  域名 ID（请求体 id）
     */
    public function setVirtualHostSsl(string $hostId, int $domainId, string $certPem, string $keyPem): void
    {
        $this->ensureToken();

        // 对齐 certimate：cert/key 均经 url-encode 后入 body；ssl_force 不设（空串）
        $this->request('POST', 'provision/custom/'.rawurlencode($hostId).'/domains', [
            'func' => 'SetSSL',
            'id' => $domainId,
            'ssl_force' => '',
            'sslCert' => rawurlencode($certPem),
            'sslKey' => rawurlencode($keyPem),
        ]);
    }

    /** 懒登录换 JWT token（进程内缓存）。 */
    private function ensureToken(): void
    {
        if ($this->token !== '') {
            return;
        }

        $json = $this->request('POST', 'v1/login_api', [
            'account' => $this->username,
            'password' => $this->password,
        ]);

        $jwt = is_string($json['jwt'] ?? null) ? $json['jwt'] : '';
        if ($jwt === '') {
            throw new MohuaApiException('AuthError', '嘿华云登录未返回 token');
        }

        $this->token = $jwt;
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但 status!=200」。
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        $options = ['json' => $body, 'http_errors' => false];
        if ($this->token !== '') {
            $options['headers'] = ['JWT' => 'Bearer '.$this->token];
        }

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $bizStatus = $json['status'] ?? null;
        if ($status >= 200 && $status < 300 && ($bizStatus === null || (int) $bizStatus === 200)) {
            return $json;
        }

        // 优先取响应体 status/msg（嘿华云标准错误体，安全：无凭证）
        $code = $bizStatus !== null && (int) $bizStatus !== 200
            ? (string) $bizStatus
            : ($status > 0 ? (string) $status : 'MohuaError');
        $message = is_string($json['msg'] ?? null) && $json['msg'] !== ''
            ? $json['msg']
            : "嘿华云接口返回 HTTP $status";

        throw new MohuaApiException($code, $message);
    }
}
