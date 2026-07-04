<?php

namespace Plugins\CloudDeploy\Deployers\Cpanel;

use GuzzleHttp\ClientInterface;

/**
 * cPanel UAPI REST 薄客户端（SSL 安装）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/cpanel 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：{serverUrl}/execute。鉴权：`Authorization: cpanel <username>:<apiToken>` 头（cPanel UAPI 令牌，
 * 非 Basic、非 Bearer，由 makeClient 构造时注入）。
 *
 * 响应体 `{status, messages:[], warnings:[], errors:[], data}` —— status==1 成功、status==0 失败。
 * HTTP 非 2xx 或 status==0 归一为 CpanelApiException（状态码 + errors/warnings/messages 拼描述，不含凭证）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖全部 REST 调用。
 */
class CpanelClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * 安装/替换站点 SSL 证书（UAPI SSL::install_ssl，GET + 查询串）。
     * REF: certimate cpanel SSLInstallSSL —— GET /SSL/install_ssl?domain=&cert=&key=&cabundle=
     *
     * @param  string  $serverCert  服务器证书 leaf PEM（不含中间证书）
     * @param  string  $privateKey  私钥 PEM
     * @param  string  $caBundle  中间证书 PEM（issuer 链，可空）
     */
    public function installSsl(string $domain, string $serverCert, string $privateKey, string $caBundle): void
    {
        // PEM 走 URL 查询串（与 certimate 一致；Guzzle 自动 URL 编码）
        $query = [
            'domain' => $domain,
            'cert' => $serverCert,
            'key' => $privateKey,
        ];
        if ($caBundle !== '') {
            $query['cabundle'] = $caBundle;
        }

        $resp = $this->http->request('GET', 'SSL/install_ssl', [
            'query' => $query,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        // HTTP 非 2xx：用响应体 errors/messages（若有）+ HTTP 状态码作错误码
        if ($status < 200 || $status >= 300) {
            throw new CpanelApiException((string) $status, self::describe($json) ?: "cPanel 接口返回 HTTP $status");
        }

        // 2xx：业务 status 字段，1=成功、0=失败（与 certimate `status != 0` 一致）
        $bizStatus = $json['status'] ?? null;
        if ((int) $bizStatus === 0) {
            throw new CpanelApiException('0', self::describe($json) ?: 'cPanel 安装证书失败');
        }
    }

    /**
     * 拼装错误描述：优先 errors，其次 warnings，再次 messages（均为响应体安全字段，不含凭证）。
     *
     * @param  array<string,mixed>  $json
     */
    private static function describe(array $json): string
    {
        foreach (['errors', 'warnings', 'messages'] as $key) {
            $list = $json[$key] ?? null;
            if (is_array($list) && $list !== []) {
                $items = array_values(array_filter(array_map(
                    fn ($v) => is_scalar($v) ? trim((string) $v) : '',
                    $list,
                ), fn ($v) => $v !== ''));
                if ($items !== []) {
                    return implode(', ', $items);
                }
            }
        }

        return '';
    }
}
