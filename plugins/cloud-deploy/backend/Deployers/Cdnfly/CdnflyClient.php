<?php

namespace Plugins\CloudDeploy\Deployers\Cdnfly;

use GuzzleHttp\ClientInterface;

/**
 * Cdnfly REST 薄客户端（自建 CDN 系统：网站 + 证书）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/cdnfly 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/v1（serverUrl 来自凭证，自建服务地址）。鉴权：API-Key + API-Secret 请求头。
 * 响应体 `{code, msg, data}` —— HTTP 非 2xx 或「code 非空且非 '0'」归一为 CdnflyApiException
 * （code + msg，不含凭证）。code 可能是字符串或数字（certimate json.RawMessage），统一归一为字符串判断。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class CdnflyClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 获取单个网站详情。
     * REF: certimate cdnfly GetSite —— GET /sites/{siteId}
     *
     * @return array<string,mixed> data 子对象（含 https_listen JSON 串）
     */
    public function getSite(string $siteId): array
    {
        $json = $this->request('GET', 'sites/'.rawurlencode($siteId), null);

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /**
     * 添加单个证书。
     * REF: certimate cdnfly CreateCert —— POST /certs {name, type:"custom", cert, key}
     *
     * @param  array<string,mixed>  $body
     * @return string 新建证书 id（响应体 data）
     */
    public function createCert(array $body): string
    {
        $json = $this->request('POST', 'certs', $body);
        $data = $json['data'] ?? null;

        return is_string($data) ? $data : (is_scalar($data) ? (string) $data : '');
    }

    /**
     * 修改单个证书。
     * REF: certimate cdnfly UpdateCert —— PUT /certs/{certId} {type:"custom", cert, key}
     *
     * @param  array<string,mixed>  $body
     */
    public function updateCert(string $certId, array $body): void
    {
        $this->request('PUT', 'certs/'.rawurlencode($certId), $body);
    }

    /**
     * 修改单个网站。
     * REF: certimate cdnfly UpdateSite —— PUT /sites/{siteId} {https_listen: {...}}
     *
     * @param  array<string,mixed>  $body
     */
    public function updateSite(string $siteId, array $body): void
    {
        $this->request('PUT', 'sites/'.rawurlencode($siteId), $body);
    }

    /**
     * 发起请求并归一错误。GET 无 body，写请求带 JSON body。http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $options = ['http_errors' => false];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $code = $this->normalizeCode($json['code'] ?? null);
        $httpOk = $status >= 200 && $status < 300;
        $codeOk = $code === '' || $code === '0';
        if ($httpOk && $codeOk) {
            return $json;
        }

        // 优先取响应体 code/msg（Cdnfly 标准错误体，安全：无凭证）
        $errCode = ! $codeOk ? $code : ($status > 0 ? (string) $status : 'CdnflyError');
        $message = is_string($json['msg'] ?? null) && $json['msg'] !== ''
            ? $json['msg']
            : "Cdnfly 接口返回 HTTP $status";

        throw new CdnflyApiException($errCode, $message);
    }

    /** code 可能是字符串或数字（certimate json.RawMessage），归一为字符串。 */
    private function normalizeCode(mixed $code): string
    {
        if ($code === null) {
            return '';
        }
        if (is_string($code)) {
            return $code;
        }
        if (is_int($code) || is_float($code)) {
            // 浮点去掉无意义小数（如 0.0 → "0"）
            return rtrim(rtrim(sprintf('%.10F', $code), '0'), '.');
        }

        return '';
    }
}
