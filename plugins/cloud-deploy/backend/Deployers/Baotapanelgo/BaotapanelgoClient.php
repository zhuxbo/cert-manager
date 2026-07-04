<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanelgo;

use GuzzleHttp\ClientInterface;

/**
 * 宝塔面板（Windows Go 版）API REST 薄客户端。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/btpanelgo 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：serverUrl（无路径前缀）。所有接口走 application/x-www-form-urlencoded POST。
 *
 * 鉴权（与 Linux 宝塔一致）：request_token = md5(request_time + md5(apiKey))，连同 request_time 作表单字段。
 *
 * 响应体 `{status, code, msg, data}`——status 可能是 bool 或 int（0=成功）；非成功归一为
 * BaotapanelgoApiException（msg 取自响应体，不含 apiKey）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖。
 */
class BaotapanelgoClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
    ) {}

    /**
     * 获取面板配置（检测 WebServer 类型，IIS 走 PFX 路径）。
     * REF: certimate panel.GetConfig —— POST /panel/get_config → site.webserver
     *
     * @return array<string,mixed>
     */
    public function panelGetConfig(): array
    {
        return $this->postForm('/panel/get_config', []);
    }

    /**
     * 按类型分页查询网站列表。
     * REF: certimate site.GetProjectList —— POST /site/get_project_list，表单 search_type/search/p/limit
     *
     * @return list<array<string,mixed>>
     */
    public function siteGetProjectList(string $searchType, string $search, int $page, int $limit): array
    {
        $json = $this->postForm('/site/get_project_list', [
            'search_type' => $searchType,
            'search' => $search,
            'p' => (string) $page,
            'limit' => (string) $limit,
        ]);

        return $this->extractData($json);
    }

    /**
     * 分页查询数据列表（空类型/IIS 网站用 table=sites）。
     * REF: certimate datalist.GetDataList —— POST /datalist/get_data_list，表单 table/search/p/limit
     *
     * @return list<array<string,mixed>>
     */
    public function datalistGetDataList(string $table, string $search, int $page, int $limit): array
    {
        $json = $this->postForm('/datalist/get_data_list', [
            'table' => $table,
            'search' => $search,
            'p' => (string) $page,
            'limit' => (string) $limit,
        ]);

        return $this->extractData($json);
    }

    /**
     * 设置网站 SSL（非 IIS）。
     * REF: certimate site.SetSiteSSL —— POST /site/set_site_ssl，表单 siteid/status/key/cert
     */
    public function siteSetSiteSSL(int $siteId, bool $status, string $certPEM, string $privkeyPEM): void
    {
        $this->postForm('/site/set_site_ssl', [
            'siteid' => (string) $siteId,
            'status' => $status ? '1' : '0',
            'key' => $privkeyPEM,
            'cert' => $certPEM,
        ]);
    }

    /**
     * 设置面板自身 SSL。
     * REF: certimate config.SetPanelSSL —— POST /config/set_panel_ssl，表单 ssl_status/ssl_key/ssl_pem
     */
    public function configSetPanelSSL(int $sslStatus, string $certPEM, string $privkeyPEM): void
    {
        $this->postForm('/config/set_panel_ssl', [
            'ssl_status' => (string) $sslStatus,
            'ssl_key' => $privkeyPEM,
            'ssl_pem' => $certPEM,
        ]);
    }

    /**
     * 发起表单 POST、加签、归一错误。
     *
     * @param  array<string,string>  $form
     * @return array<string,mixed>
     */
    private function postForm(string $path, array $form): array
    {
        $timestamp = (string) time();
        $form['request_time'] = $timestamp;
        $form['request_token'] = md5($timestamp.md5($this->apiKey));

        $resp = $this->http->request('POST', ltrim($path, '/'), [
            'http_errors' => false,
            'form_params' => $form,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            throw new BaotapanelgoApiException('BaotaError', "宝塔面板接口返回 HTTP $status");
        }

        // status 可能是 bool 或 int（0=成功）；缺省视为成功
        $st = $json['status'] ?? null;
        if ($this->isErrored($st)) {
            $msg = is_string($json['msg'] ?? null) && $json['msg'] !== '' ? $json['msg'] : '宝塔面板接口返回错误';
            throw new BaotapanelgoApiException('BaotaError', $msg);
        }

        return $json;
    }

    /** status 判错：false / 非 0 整数 视为失败；true / 0 / null 视为成功。 */
    private function isErrored(mixed $status): bool
    {
        if ($status === null) {
            return false;
        }
        if (is_bool($status)) {
            return $status === false;
        }
        if (is_int($status) || (is_string($status) && is_numeric($status))) {
            return (int) $status !== 0;
        }

        return false;
    }

    /**
     * 抽取分页响应的 data 列表（仅保留数组项）。
     *
     * @param  array<string,mixed>  $json
     * @return list<array<string,mixed>>
     */
    private function extractData(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        return array_values(array_filter($data, 'is_array'));
    }
}
