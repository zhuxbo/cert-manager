<?php

namespace Plugins\CloudDeploy\Deployers\Rainyun;

use GuzzleHttp\ClientInterface;

/**
 * 雨云 API v2 REST 薄客户端（SSL 证书中心 + RCDN 实例绑定）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/rainyun 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：https://api.v2.rainyun.com。鉴权：API Key（X-API-Key 请求头）。响应体 `{code, message, data}` ——
 * HTTP 非 2xx 或「code/100 != 2」归一为 RainyunApiException（code + message，不含凭证）。
 *
 * 此类是 deployer/uploader 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class RainyunClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 新建 SSL 证书（证书中心）。
     * REF: certimate rainyun SslCenterCreate —— POST /product/sslcenter/ {cert, key}
     */
    public function sslCenterCreate(string $certPem, string $keyPem): void
    {
        $this->request('POST', 'product/sslcenter/', ['json' => ['cert' => $certPem, 'key' => $keyPem]]);
    }

    /**
     * 替换 SSL 证书（证书中心）。
     * REF: certimate rainyun SslCenterUpdate —— PUT /product/sslcenter/{certId} {cert, key}
     */
    public function sslCenterUpdate(int $certId, string $certPem, string $keyPem): void
    {
        $this->request('PUT', "product/sslcenter/$certId", ['json' => ['cert' => $certPem, 'key' => $keyPem]]);
    }

    /**
     * 按域名分页查询 SSL 证书列表。
     * REF: certimate rainyun SslCenterList —— GET /product/sslcenter?options={JSON}
     * options 形如 {columnFilters:{Domain}, page, perPage}（整体 JSON 串作单个查询参 options）。
     *
     * @return array{records: list<array<string,mixed>>, total: int}
     */
    public function sslCenterList(string $domain, int $page, int $perPage): array
    {
        $options = json_encode([
            'columnFilters' => ['Domain' => $domain],
            'page' => $page,
            'perPage' => $perPage,
        ]);

        $json = $this->request('GET', 'product/sslcenter', ['query' => ['options' => $options]]);
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $records = is_array($data['Records'] ?? null) ? $data['Records'] : [];
        $total = is_int($data['TotalRecords'] ?? null) ? $data['TotalRecords'] : (int) ($data['TotalRecords'] ?? 0);

        return ['records' => array_values($records), 'total' => $total];
    }

    /**
     * 获取单个 SSL 证书详情（含证书内容）。
     * REF: certimate rainyun SslCenterGet —— GET /product/sslcenter/{sslId}
     *
     * @return array<string,mixed> data 子对象（含 Cert/Key）
     */
    public function sslCenterGet(int $sslId): array
    {
        $json = $this->request('GET', "product/sslcenter/$sslId", []);

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /**
     * RCDN 实例 SSL 绑定域名。
     * REF: certimate rainyun-rcdn RcdnInstanceSslBind —— POST /product/rcdn/instance/{id}/ssl_bind
     * {cert_id, domains}
     *
     * @param  list<string>  $domains
     */
    public function rcdnInstanceSslBind(int $instanceId, int $certId, array $domains): void
    {
        $this->request('POST', "product/rcdn/instance/$instanceId/ssl_bind", [
            'json' => ['cert_id' => $certId, 'domains' => $domains],
        ]);
    }

    /**
     * 发起请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但 code/100 != 2」。
     *
     * @param  array<string,mixed>  $options  额外 Guzzle 选项（json / query）
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $options['http_errors'] = false;
        $resp = $this->http->request($method, $path, $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $bizCode = $json['code'] ?? null;
        $bizOk = $bizCode === null || (is_numeric($bizCode) && intdiv((int) $bizCode, 100) === 2);
        if ($status >= 200 && $status < 300 && $bizOk) {
            return $json;
        }

        // 优先取响应体 code/message（雨云标准错误体，安全：无凭证）
        $code = $bizCode !== null && ! $bizOk
            ? (string) $bizCode
            : ($status > 0 ? (string) $status : 'RainyunError');
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "雨云接口返回 HTTP $status";

        throw new RainyunApiException($code, $message);
    }
}
