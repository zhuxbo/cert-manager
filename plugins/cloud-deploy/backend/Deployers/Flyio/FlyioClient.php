<?php

namespace Plugins\CloudDeploy\Deployers\Flyio;

use GuzzleHttp\ClientInterface;

/**
 * Fly.io Machines API REST 薄客户端（自定义证书 certificates）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/flyio 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Bearer Token（Authorization 请求头）。base：https://api.machines.dev/v1。
 * Fly.io 失败响应体形如 `{error: "..."}` —— HTTP 非 2xx 或响应体带 error 归一为
 * FlyioApiException（错误码 + 描述取自响应体，不含 token）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖
 * 全部 REST 调用，无需打真实 HTTP。
 */
class FlyioClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 导入自定义证书。
     * REF: certimate flyio ImportCustomCertificate —— POST /apps/{appName}/certificates/custom
     *
     * @param  array<string,mixed>  $body  hostname / fullchain / private_key
     */
    public function importCustomCertificate(string $appName, array $body): void
    {
        // 相对路径（无前导 /）：与 base_uri ".../v1/" 合并，避免绝对路径替换 base path。appName 编码进路径段。
        $this->request('POST', 'apps/'.rawurlencode($appName).'/certificates/custom', $body);
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但响应体带 error」。
     *
     * @param  array<string,mixed>  $body
     */
    private function request(string $method, string $path, array $body): void
    {
        $resp = $this->http->request($method, $path, [
            'json' => $body,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $error = is_string($json['error'] ?? null) ? $json['error'] : '';
        if ($status >= 200 && $status < 300 && $error === '') {
            return;
        }

        // 错误码用 HTTP 状态，描述优先取响应体 error（Fly.io 标准错误体，安全：无凭证）
        $code = $status > 0 ? (string) $status : 'FlyioError';
        $message = $error !== '' ? $error : "Fly.io 接口返回 HTTP $status";

        throw new FlyioApiException($code, $message);
    }
}
