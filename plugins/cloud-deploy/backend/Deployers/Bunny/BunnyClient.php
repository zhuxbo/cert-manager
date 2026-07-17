<?php

namespace Plugins\CloudDeploy\Deployers\Bunny;

use GuzzleHttp\ClientInterface;

/**
 * Bunny REST 薄客户端（Pull Zone 自定义证书）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/bunny 用 GuzzleHttp（来自主系统 vendor）直调。
 * 鉴权：AccessKey 请求头（非 Bearer）。base：https://api.bunny.net。
 * Bunny 仅按 HTTP 状态码判失败（无业务错误码体），HTTP 非 2xx 归一为 BunnyApiException
 * （错误码=HTTP 状态，描述尽力取响应体 Message/message，不含 key）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class BunnyClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 为 Pull Zone 添加自定义证书。
     * REF: certimate bunny AddCustomCertificate —— POST /pullzone/{pullZoneId}/addCertificate
     *
     * @param  array<string,mixed>  $body  Hostname / Certificate(base64) / CertificateKey(base64)
     */
    public function addCustomCertificate(string $pullZoneId, array $body): void
    {
        // 相对路径（无前导 /）：与 base_uri "https://api.bunny.net" 合并。pullZoneId 编码进路径段。
        $this->request('POST', 'pullzone/'.rawurlencode($pullZoneId).'/addCertificate', $body);
    }

    /**
     * 发起 JSON 写请求并归一错误。http_errors=false 自行判状态。
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
        if ($status >= 200 && $status < 300) {
            return;
        }

        // Bunny 无标准错误码体；尽力从响应体取 Message/message 作描述（安全：无凭证）
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];
        $message = '';
        foreach (['Message', 'message'] as $key) {
            if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                $message = $json[$key];
                break;
            }
        }
        $code = $status > 0 ? (string) $status : 'BunnyError';
        if ($message === '') {
            $message = "Bunny 接口返回 HTTP $status";
        }

        throw new BunnyApiException($code, $message);
    }
}
