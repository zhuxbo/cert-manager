<?php

namespace Plugins\CloudDeploy\Deployers\Samwaf;

use GuzzleHttp\ClientInterface;

/**
 * SamWaf REST 薄客户端（SSL 配置查询 + 编辑）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/samwaf 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/api/v1（注意：SamWaf 在 base 上追加 /api/v1，与 SafeLine 不同）。
 * 鉴权：`X-API-Key: <key>` 头（由 makeClient 注入）。
 *
 * 响应体 `{code, msg, data}` —— code==0 成功、非 0 失败。HTTP 非 2xx 或 code≠0 归一为
 * SamwafApiException（code / HTTP 状态码 + msg，不含凭证）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class SamwafClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 查询 SSL 配置详情（用于确认目标证书存在）。
     * REF: certimate samwaf SslConfigDetail —— GET /sslconfig/detail?id=<id>
     *
     * @return array<string,mixed>|null data.SSLConfig（字段 snake_case：id/cert_content/...），缺失返回 null
     */
    public function getSslConfigDetail(string $sslId): ?array
    {
        $json = $this->call('GET', 'sslconfig/detail', null, ['id' => $sslId]);
        $data = $json['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * 编辑 SSL 配置（替换证书内容）。注意：是 POST（非 PUT），对齐 certimate。
     * REF: certimate samwaf SslConfigEdit —— POST /sslconfig/edit
     *   {id, cert_content, cert_path:"", key_content, key_path:""}（snake_case；path 字段无 omitempty，原样空串外发）
     */
    public function editSslConfig(string $sslId, string $certContent, string $keyContent): void
    {
        $this->call('POST', 'sslconfig/edit', [
            'id' => $sslId,
            'cert_content' => $certContent,
            'cert_path' => '',
            'key_content' => $keyContent,
            'key_path' => '',
        ]);
    }

    /**
     * 发起 JSON 请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但 code≠0」。
     *
     * @param  array<string,mixed>|null  $body  JSON 请求体（GET 传 null）
     * @param  array<string,string>|null  $query  查询参数
     * @return array<string,mixed> 解析后的响应体
     */
    private function call(string $method, string $path, ?array $body, ?array $query = null): array
    {
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

        $msg = is_string($json['msg'] ?? null) && $json['msg'] !== '' ? $json['msg'] : '';

        // HTTP 非 2xx：用响应体 msg（若有）+ HTTP 状态码作错误码
        if ($status < 200 || $status >= 300) {
            throw new SamwafApiException((string) $status, $msg !== '' ? $msg : "SamWaf 接口返回 HTTP $status");
        }

        // 2xx 但 code≠0：业务错误（code 为整数，0 视为成功；缺失键视为 0/成功）
        $code = $json['code'] ?? 0;
        if ((int) $code !== 0) {
            throw new SamwafApiException((string) $code, $msg !== '' ? $msg : 'SamWaf 接口返回错误');
        }

        return $json;
    }
}
