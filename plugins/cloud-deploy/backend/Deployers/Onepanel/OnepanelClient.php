<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

use GuzzleHttp\ClientInterface;

/**
 * 1Panel API REST 薄客户端（v1 + v2 统一封装）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/1panel(+v2) 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/api/v1 或 /api/v2（按 apiVersion）。
 *
 * 鉴权（v1/v2 一致）：每次请求即时算 token = md5("1panel" + apiKey + unixTimestamp)，置请求头
 *   1Panel-Timestamp + 1Panel-Token；v2 额外带 CurrentNode 头（节点名，默认 local）。
 *   apiKey 不出现在 URL/body。
 *
 * 响应体 `{code, message, data}`——HTTP 非 2xx 或 code/100 != 2 归一为 OnepanelApiException
 * （业务码 + message，不含 apiKey）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部
 * REST 调用，无需打真实 HTTP。
 */
class OnepanelClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly bool $isV2,
        private readonly string $nodeName,
    ) {}

    /** 是否 v2（deployer 据此切 console 路径 / Http3 字段 / ssl 枚举大小写）。 */
    public function isV2(): bool
    {
        return $this->isV2;
    }

    /**
     * 上传 / 替换网站证书。
     * REF: certimate WebsiteSSLUpload —— POST /websites/ssl/upload
     *
     * @param  array<string,mixed>  $body
     */
    public function uploadWebsiteSSL(array $body): void
    {
        $this->request('POST', '/websites/ssl/upload', $body);
    }

    /**
     * 分页搜索网站证书（用于按内容去重 + 取刚上传证书 ID）。
     * REF: certimate WebsiteSSLSearch —— POST /websites/ssl/search
     *
     * @param  array<string,mixed>  $body
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function searchWebsiteSSL(array $body): array
    {
        $json = $this->request('POST', '/websites/ssl/search', $body);

        return $this->extractItems($json);
    }

    /**
     * 分页搜索网站（CERTSAN 匹配模式用）。
     * REF: certimate WebsiteSearch —— POST /websites/search
     *
     * @param  array<string,mixed>  $body
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function searchWebsites(array $body): array
    {
        $json = $this->request('POST', '/websites/search', $body);

        return $this->extractItems($json);
    }

    /**
     * 获取网站详情（含 domains）。
     * REF: certimate WebsiteGet —— GET /websites/{id}
     *
     * @return array<string,mixed>
     */
    public function getWebsite(int $websiteId): array
    {
        $json = $this->request('GET', "/websites/$websiteId", null);
        $data = $json['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * 获取网站 HTTPS 配置。
     * REF: certimate WebsiteHttpsGet —— GET /websites/{id}/https
     *
     * @return array<string,mixed>
     */
    public function getWebsiteHttps(int $websiteId): array
    {
        $json = $this->request('GET', "/websites/$websiteId/https", null);
        $data = $json['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * 修改网站 HTTPS 配置（绑定证书）。
     * REF: certimate WebsiteHttpsPost —— POST /websites/{id}/https
     *
     * @param  array<string,mixed>  $body
     */
    public function postWebsiteHttps(int $websiteId, array $body): void
    {
        $this->request('POST', "/websites/$websiteId/https", $body);
    }

    /**
     * 设置面板自身 SSL 证书。
     * REF: certimate SettingsSSLUpdate(v1) / CoreSettingsSSLUpdate(v2)
     *   v1 —— POST /settings/ssl/update；v2 —— POST /core/settings/ssl/update
     *
     * @param  array<string,mixed>  $body
     */
    public function updatePanelSSL(array $body): void
    {
        $path = $this->isV2 ? '/core/settings/ssl/update' : '/settings/ssl/update';
        $this->request('POST', $path, $body);
    }

    /**
     * 发起请求并归一错误。http_errors=false 自行判状态，兼容「2xx 但 code/100 != 2」。
     *
     * @param  array<string,mixed>|null  $body  JSON 请求体（GET 传 null）
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $timestamp = (string) time();
        $token = md5('1panel'.$this->apiKey.$timestamp);

        $headers = [
            'Accept' => 'application/json',
            '1Panel-Timestamp' => $timestamp,
            '1Panel-Token' => $token,
        ];
        if ($this->isV2) {
            $headers['CurrentNode'] = $this->nodeName !== '' ? $this->nodeName : 'local';
        }

        $options = [
            'http_errors' => false,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        // 相对路径去前导 /（base_uri 已含 /api/vN/），避免绝对路径替换 base path
        $resp = $this->http->request($method, ltrim($path, '/'), $options);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        $code = $json['code'] ?? null;
        $message = is_string($json['message'] ?? null) ? $json['message'] : '';

        if ($status < 200 || $status >= 300) {
            throw new OnepanelApiException(
                $code !== null ? (string) $code : (string) $status,
                $message !== '' ? $message : "1Panel 接口返回 HTTP $status",
            );
        }

        // 2xx 但 code/100 != 2（1Panel 业务错误码约定）
        if ($code !== null && (int) ((int) $code / 100) !== 2) {
            throw new OnepanelApiException((string) $code, $message !== '' ? $message : '1Panel 接口返回错误');
        }

        return $json;
    }

    /**
     * 抽取分页响应的 items + total（data.items / data.total）。
     *
     * @param  array<string,mixed>  $json
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    private function extractItems(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $total = (int) ($data['total'] ?? 0);

        return [
            'items' => array_values(array_filter($items, 'is_array')),
            'total' => $total,
        ];
    }
}
