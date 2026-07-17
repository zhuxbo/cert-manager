<?php

namespace Plugins\CloudDeploy\Deployers\Safeline;

use GuzzleHttp\ClientInterface;

/**
 * 雷池 WAF（SafeLine）开放接口 REST 薄客户端（更新证书）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/safeline 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}（无路径前缀）。鉴权：`X-SLCE-API-TOKEN: <token>` 头（由 makeClient 注入）。
 *
 * 响应体 `{err, msg, data}` —— `err` 为空/缺失视为成功，非空即业务失败。HTTP 非 2xx 或 err 非空归一为
 * SafelineApiException（err 码 / HTTP 状态码 + msg，不含凭证）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class SafelineClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 更新指定证书内容（手动证书，type=2）。
     * REF: certimate safeline UpdateCertificate —— POST /api/open/cert
     *   {id:<int>, type:2, manual:{crt:<certPEM>, key:<keyPEM>}}
     *
     * @param  int  $certificateId  雷池证书 ID（数字）
     */
    public function updateCertificate(int $certificateId, string $certPem, string $keyPem): void
    {
        $resp = $this->http->request('POST', 'api/open/cert', [
            'json' => [
                'id' => $certificateId,
                'type' => 2,
                'manual' => [
                    'crt' => $certPem,
                    'key' => $keyPem,
                ],
            ],
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $err = $json['err'] ?? null;
        $msg = is_string($json['msg'] ?? null) && $json['msg'] !== '' ? $json['msg'] : '';

        // HTTP 非 2xx：用响应体 msg（若有）+ HTTP 状态码作错误码
        if ($status < 200 || $status >= 300) {
            throw new SafelineApiException((string) $status, $msg !== '' ? $msg : "雷池 WAF 接口返回 HTTP $status");
        }

        // 2xx 但 err 非空：业务错误（err 为字符串，空/null 视为成功）
        if ($err !== null && (string) $err !== '') {
            throw new SafelineApiException((string) $err, $msg !== '' ? $msg : '雷池 WAF 更新证书失败');
        }
    }
}
