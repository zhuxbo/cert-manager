<?php

namespace Plugins\CloudDeploy\Deployers\Vercel;

use GuzzleHttp\ClientInterface;

/**
 * Vercel REST 薄客户端（证书 certs）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/vercel 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：Bearer Token（Authorization 请求头）。base：https://api.vercel.com/v8。
 * Vercel 失败响应体形如 `{error:{code,message}}` —— HTTP 非 2xx 或响应体带 error.code 归一为
 * VercelApiException（错误码 + 描述取自响应体 error，不含 token）。
 * teamId（团队下操作）作查询参 ?teamId= 传入（对齐 certimate 的 SetPreRequestHook）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖
 * 全部 REST 调用，无需打真实 HTTP。
 */
class VercelClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 上传证书。
     * REF: certimate vercel UploadCert —— PUT /v8/certs（teamId 非空则带查询参）
     *
     * @param  array<string,mixed>  $body
     */
    public function uploadCert(array $body, string $teamId = ''): void
    {
        // 相对路径（无前导 /）：与 base_uri ".../v8/" 合并，避免绝对路径替换 base path
        $query = $teamId !== '' ? ['teamId' => $teamId] : [];
        $this->request('PUT', 'certs', $body, $query);
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但响应体带 error」。
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $query
     */
    private function request(string $method, string $path, array $body, array $query = []): void
    {
        $options = ['json' => $body, 'http_errors' => false];
        if ($query !== []) {
            $options['query'] = $query;
        }

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        if ($status >= 200 && $status < 300 && ! isset($error['code'])) {
            return;
        }

        // 优先取响应体 error（Vercel 标准错误体，安全：code+message 无凭证）
        $code = isset($error['code']) ? (string) $error['code'] : ($status > 0 ? (string) $status : 'VercelError');
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "Vercel 接口返回 HTTP $status";

        throw new VercelApiException($code, $message);
    }
}
