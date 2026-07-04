<?php

namespace Plugins\CloudDeploy\Deployers\Baotawaf;

use GuzzleHttp\ClientInterface;

/**
 * 堡塔云 WAF API REST 薄客户端（aaWAF）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/btwaf 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/api。所有接口走 application/json POST。
 *
 * 鉴权：每次请求即时算 waf_request_token = md5(waf_request_time + md5(apiKey))，连同 waf_request_time
 *   （unix 秒）置请求头。apiKey 不出现在 URL/body。
 *
 * 响应体 `{code, ...}`——HTTP 非 2xx 或 code != 0 归一为 BaotawafApiException（业务码，不含 apiKey）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖。
 */
class BaotawafClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
    ) {}

    /**
     * 分页查询网站列表。
     * REF: certimate wafmastersite.GetSiteList —— POST /wafmastersite/get_site_list，JSON {site_name,p,p_size}
     *   → res.list[] 每项 {site_id, site_name, ...}
     *
     * @return list<array<string,mixed>>
     */
    public function getSiteList(string $siteName, int $page, int $pageSize): array
    {
        $json = $this->postJson('/wafmastersite/get_site_list', [
            'site_name' => $siteName,
            'p' => $page,
            'p_size' => $pageSize,
        ]);

        $res = is_array($json['res'] ?? null) ? $json['res'] : [];
        $list = is_array($res['list'] ?? null) ? $res['list'] : [];

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * 修改网站配置（开启证书）。
     * REF: certimate wafmastersite.ModifySite —— POST /wafmastersite/modify_site，
     *   JSON {site_id, types:"openCert", server:{listen_ssl_port:["443"], ssl:{is_ssl:1, full_chain, private_key}}}
     */
    public function modifySiteCertificate(string $siteId, int $sslPort, string $certPEM, string $privkeyPEM): void
    {
        $this->postJson('/wafmastersite/modify_site', [
            'site_id' => $siteId,
            'types' => 'openCert',
            'server' => [
                'listen_ssl_port' => [(string) $sslPort],
                'ssl' => [
                    'is_ssl' => 1,
                    'full_chain' => $certPEM,
                    'private_key' => $privkeyPEM,
                ],
            ],
        ]);
    }

    /**
     * 设置 WAF 面板自身 SSL 证书。
     * REF: certimate config.SetCert —— POST /config/set_cert，JSON {certContent, keyContent}
     */
    public function configSetCert(string $certPEM, string $privkeyPEM): void
    {
        $this->postJson('/config/set_cert', [
            'certContent' => $certPEM,
            'keyContent' => $privkeyPEM,
        ]);
    }

    /**
     * 发起 JSON POST、加签（请求头）、归一错误。
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $timestamp = (string) time();
        $token = md5($timestamp.md5($this->apiKey));

        $resp = $this->http->request('POST', ltrim($path, '/'), [
            'http_errors' => false,
            'json' => $body,
            'headers' => [
                'Accept' => 'application/json',
                'waf_request_time' => $timestamp,
                'waf_request_token' => $token,
            ],
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            throw new BaotawafApiException('BaotaWafError', "堡塔云 WAF 接口返回 HTTP $status");
        }

        // code != 0 视为错误（缺省 code 视为成功）
        $code = $json['code'] ?? null;
        if ($code !== null && (int) $code !== 0) {
            $msg = is_string($json['msg'] ?? null) && $json['msg'] !== '' ? $json['msg'] : '堡塔云 WAF 接口返回错误';
            throw new BaotawafApiException((string) $code, $msg);
        }

        return $json;
    }
}
