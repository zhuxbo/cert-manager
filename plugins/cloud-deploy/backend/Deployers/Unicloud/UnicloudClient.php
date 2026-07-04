<?php

namespace Plugins\CloudDeploy\Deployers\Unicloud;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * uniCloud（DCloud）REST 薄客户端（托管网站证书）。
 *
 * 无官方 PHP SDK，忠实移植 certimate pkg/sdk3rd/dcloud/unicloud（其自称 "mock HTTP client"——模拟控制台
 * 前端调用 uniCloud serverless）。用 GuzzleHttp（来自主系统 vendor）直调。
 *
 * 鉴权两级（懒执行 + 进程内缓存）：
 *   ① serverless token：POST {uniIdentityEndpoint} 调 uni-id-co.login（HMAC-MD5 签名）→ data.newToken.token；
 *   ② apiUser token：POST {uniConsoleEndpoint} 调 uni-cloud-kernel(user/getUserToken) → 嵌套 data.data.data.token；
 *   ③ 业务请求：POST {uniApiBase}/host/create-domain-with-cert，带 `Token` 请求头。
 *
 * 签名：generateSignature 对 payload 键升序拼 "k=v&..."，HMAC-MD5(canonical, clientSecret) 十六进制。
 *
 * **与 certimate 的有意偏差**：certimate 登录 params 写死 `password:"password"`（疑似其源码笔误，会使真实登录
 * 必失败）；本移植改用凭证里的真实 password（用户在凭证表单填的控制台密码），否则功能不可用。其余字段名/
 * 结构/签名算法严格对齐 certimate。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class UnicloudClient
{
    // 对齐 certimate dcloud/unicloud client.go 的硬编码端点 + magic 常量（uniCloud 控制台前端同款）
    private const UNI_IDENTITY_ENDPOINT = 'https://account.dcloud.net.cn/client';

    private const UNI_IDENTITY_CLIENT_SECRET = 'ba461799-fde8-429f-8cc4-4b6d306e2339';

    private const UNI_IDENTITY_APP_ID = '__UNI__uniid_server';

    private const UNI_IDENTITY_SPACE_ID = 'uni-id-server';

    private const UNI_CONSOLE_ENDPOINT = 'https://unicloud.dcloud.net.cn/client';

    private const UNI_CONSOLE_CLIENT_SECRET = '4c1f7fbf-c732-42b0-ab10-4634a8bbe834';

    private const UNI_CONSOLE_APP_ID = '__UNI__unicloud_console';

    private const UNI_CONSOLE_SPACE_ID = 'dc-6nfabcn6ada8d3dd';

    private const UNI_API_BASE = 'https://unicloud-api.dcloud.net.cn/unicloud/api';

    /** 客户端标识（对齐 certimate app.AppName 用途，仅作 deviceId / X-Client-Info 元数据，不参与鉴权安全）。 */
    private const APP_NAME = 'clouddeploy';

    private string $serverlessToken = '';

    private int $serverlessTokenExpireMs = 0;

    private string $apiUserToken = '';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /**
     * 变更托管网站证书。
     * REF: certimate unicloud-webhost CreateDomainWithCert —— POST {uniApiBase}/host/create-domain-with-cert
     * body {provider, spaceId, domain, cert(url-encoded), key(url-encoded)}（带 Token 请求头）。
     *
     * @param  array<string,mixed>  $body
     */
    public function createDomainWithCert(array $body): void
    {
        $this->ensureApiUserToken();

        $resp = $this->http->request('POST', self::UNI_API_BASE.'/host/create-domain-with-cert', [
            'json' => $body,
            'http_errors' => false,
            'headers' => ['Token' => $this->apiUserToken, 'Content-Type' => 'application/json'],
        ]);

        $json = $this->decode($resp);
        $this->guardApiUser($resp->getStatusCode(), $json);
    }

    /** 懒登录换 serverless token（uni-id-co.login）。 */
    private function ensureServerlessToken(): void
    {
        if ($this->serverlessToken !== '' && $this->serverlessTokenExpireMs > (int) (microtime(true) * 1000)) {
            return;
        }

        // 账号类型判定：手机号 / 邮箱 / 用户名（对齐 certimate 正则）
        $params = ['password' => $this->password];
        if (preg_match('/^1\d{10}$/', $this->username)) {
            $params['mobile'] = $this->username;
        } elseif (preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $this->username)) {
            $params['email'] = $this->username;
        } else {
            $params['username'] = $this->username;
        }

        $json = $this->invokeServerless(
            self::UNI_IDENTITY_ENDPOINT,
            self::UNI_IDENTITY_CLIENT_SECRET,
            self::UNI_IDENTITY_APP_ID,
            self::UNI_IDENTITY_SPACE_ID,
            'uni-id-co',
            'login',
            '',
            $params,
            null,
        );

        $newToken = $json['data']['newToken'] ?? null;
        $token = is_array($newToken) && is_string($newToken['token'] ?? null) ? $newToken['token'] : '';
        $expired = is_array($newToken) ? (int) ($newToken['tokenExpired'] ?? 0) : 0;
        if ($token === '' || $expired === 0) {
            throw new UnicloudApiException('AuthError', 'uniCloud 登录未返回有效 token');
        }

        $this->serverlessToken = $token;
        $this->serverlessTokenExpireMs = $expired;
    }

    /** 懒换 apiUser token（uni-cloud-kernel user/getUserToken），依赖 serverless token。 */
    private function ensureApiUserToken(): void
    {
        $this->ensureServerlessToken();

        if ($this->apiUserToken !== '') {
            return;
        }

        $json = $this->invokeServerless(
            self::UNI_CONSOLE_ENDPOINT,
            self::UNI_CONSOLE_CLIENT_SECRET,
            self::UNI_CONSOLE_APP_ID,
            self::UNI_CONSOLE_SPACE_ID,
            'uni-cloud-kernel',
            '',
            'user/getUserToken',
            null,
            ['isLogin' => true],
        );

        // 嵌套三层 data：data.data.data.token（对齐 certimate getUserTokenResponse）
        $token = $json['data']['data']['data']['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new UnicloudApiException('AuthError', 'uniCloud 获取 apiUser token 失败');
        }

        $this->apiUserToken = $token;
    }

    /**
     * 调用 uniCloud serverless（HMAC-MD5 签名 + X-Client-* 头）。
     *
     * @param  array<string,mixed>|null  $params
     * @param  array<string,mixed>|null  $data
     * @return array<string,mixed>
     */
    private function invokeServerless(
        string $endpoint,
        string $clientSecret,
        string $appId,
        string $spaceId,
        string $target,
        string $method,
        string $action,
        ?array $params,
        ?array $data,
    ): array {
        $payload = $this->buildServerlessPayload($appId, $spaceId, $target, $method, $action, $params, $data);
        $clientInfo = $this->buildClientInfo($appId);
        $sign = $this->generateSignature($payload, $clientSecret);

        $resp = $this->http->request('POST', $endpoint, [
            'json' => $payload,
            'http_errors' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Origin' => 'https://unicloud.dcloud.net.cn',
                'Referer' => 'https://unicloud.dcloud.net.cn',
                'X-Client-Info' => (string) json_encode($clientInfo),
                'X-Client-Token' => $this->serverlessToken,
                'X-Serverless-Sign' => $sign,
            ],
        ]);

        $json = $this->decode($resp);
        $this->guardServerless($resp->getStatusCode(), $json);

        return $json;
    }

    /**
     * 构造 serverless 调用 payload（对齐 certimate buildServerlessPayloadInfo）。
     *
     * @param  array<string,mixed>|null  $params
     * @param  array<string,mixed>|null  $data
     * @return array<string,mixed>
     */
    private function buildServerlessPayload(
        string $appId,
        string $spaceId,
        string $target,
        string $method,
        string $action,
        ?array $params,
        ?array $data,
    ): array {
        $functionArgs = [
            'clientInfo' => $this->buildClientInfo($appId),
            'uniIdToken' => $this->serverlessToken,
        ];
        if ($method !== '') {
            $functionArgs['method'] = $method;
            $functionArgs['params'] = [];
        }
        if ($action !== '') {
            $functionArgs['action'] = $action;
            $functionArgs['data'] = (object) [];
        }
        if ($params !== null) {
            $functionArgs['params'] = [$params];
        }
        if ($data !== null) {
            $functionArgs['data'] = $data;
        }

        $inner = (string) json_encode([
            'functionTarget' => $target,
            'functionArgs' => $functionArgs,
        ]);

        return [
            'method' => 'serverless.function.runtime.invoke',
            'params' => $inner,
            'spaceId' => $spaceId,
            'timestamp' => (int) (microtime(true) * 1000),
        ];
    }

    /**
     * 构造客户端信息（对齐 certimate buildServerlessClientInfo）。
     *
     * @return array<string,mixed>
     */
    private function buildClientInfo(string $appId): array
    {
        // certimate 用 runtime.GOOS；移植固定为 linux（仅元数据，不参与鉴权安全）
        $os = 'linux';

        return [
            'PLATFORM' => 'web',
            'OS' => strtoupper($os),
            'APPID' => $appId,
            'DEVICEID' => self::APP_NAME,
            'LOCALE' => 'zh-Hans',
            'osName' => $os,
            'appId' => $appId,
            'appName' => 'uniCloud',
            'deviceId' => self::APP_NAME,
            'deviceType' => 'pc',
            'uniPlatform' => 'web',
            'uniCompilerVersion' => '4.45',
            'uniRuntimeVersion' => '4.45',
        ];
    }

    /**
     * HMAC-MD5 签名：payload 键升序拼 "k=v&..."，HMAC-MD5(canonical, secret) 十六进制。
     * 对齐 certimate generateSignature。
     *
     * @param  array<string,mixed>  $params
     */
    private function generateSignature(array $params, string $secret): string
    {
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k.'='.$this->stringify($v);
        }

        return hash_hmac('md5', implode('&', $parts), $secret);
    }

    /** 把签名值归一为字符串（对齐 Go fmt.Sprintf("%v")：int 直出、bool→true/false）。 */
    private function stringify(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return (string) $v;
    }

    /**
     * @param  array<string,mixed>  $json
     */
    private function guardServerless(int $status, array $json): void
    {
        $success = $json['success'] ?? null;
        if ($status >= 200 && $status < 300 && $success === true) {
            return;
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = is_string($error['code'] ?? null) && $error['code'] !== ''
            ? $error['code']
            : ($status > 0 ? (string) $status : 'UniCloudError');
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "uniCloud 接口返回 HTTP $status";

        throw new UnicloudApiException($code, $message);
    }

    /**
     * @param  array<string,mixed>  $json
     */
    private function guardApiUser(int $status, array $json): void
    {
        $ret = $json['ret'] ?? null;
        if ($status >= 200 && $status < 300 && ($ret === null || (int) $ret === 0)) {
            return;
        }

        $code = $ret !== null && (int) $ret !== 0
            ? (string) $ret
            : ($status > 0 ? (string) $status : 'UniCloudError');
        $message = is_string($json['desc'] ?? null) && $json['desc'] !== ''
            ? $json['desc']
            : "uniCloud 接口返回 HTTP $status";

        throw new UnicloudApiException($code, $message);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $resp): array
    {
        $json = json_decode((string) $resp->getBody(), true);

        return is_array($json) ? $json : [];
    }
}
