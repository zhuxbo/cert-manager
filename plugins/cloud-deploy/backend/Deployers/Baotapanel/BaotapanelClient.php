<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanel;

use GuzzleHttp\ClientInterface;

/**
 * 宝塔面板 API REST 薄客户端（aaPanel/宝塔 Linux 面板）。
 *
 * 无官方 PHP SDK，照 certimate pkg/sdk3rd/btpanel 用 GuzzleHttp（来自主系统 vendor）直调。
 * base：serverUrl（无路径前缀）。所有接口走 application/x-www-form-urlencoded POST。
 *
 * 鉴权：每次请求即时算 request_token = md5(request_time + md5(apiKey))，连同 request_time（unix 秒）
 *   作表单字段提交。apiKey 不出现在 URL。
 *
 * 响应体两种形态：
 *   - v1：`{status:bool, msg}` —— status=false 视为错误。
 *   - v2（ssl_domain 系列）：`{status:int(0=成功), message:{...}}` —— status!=0 视为错误。
 * 归一为 BaotapanelApiException（msg 取自响应体，不含 apiKey）。
 *
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖。
 */
class BaotapanelClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
    ) {}

    /**
     * 设置站点 SSL 证书（非代理站点）。
     * REF: certimate site.SetSSL —— POST /site?action=SetSSL，表单 type/siteName/key/csr
     */
    public function siteSetSSL(string $siteName, string $certPEM, string $privkeyPEM): void
    {
        $this->postForm('/site?action=SetSSL', [
            'type' => '0',
            'siteName' => $siteName,
            'key' => $privkeyPEM,
            'csr' => $certPEM,
        ]);
    }

    /**
     * 设置代理站点 SSL 证书。
     * REF: certimate mod.proxy.com.SetSSL —— POST /mod/proxy/com/set_ssl，表单 site_name/key/csr
     */
    public function modProxyComSetSSL(string $siteName, string $certPEM, string $privkeyPEM): void
    {
        $this->postForm('/mod/proxy/com/set_ssl', [
            'site_name' => $siteName,
            'key' => $privkeyPEM,
            'csr' => $certPEM,
        ]);
    }

    /**
     * 上传证书到证书库（v1）。
     * REF: certimate ssl.cert.SaveCert —— POST /ssl/cert/save_cert，表单 key/csr → 响应 ssl_hash
     *
     * @return string ssl_hash
     */
    public function sslCertSaveCert(string $certPEM, string $privkeyPEM): string
    {
        $json = $this->postForm('/ssl/cert/save_cert', [
            'key' => $privkeyPEM,
            'csr' => $certPEM,
        ]);

        return is_string($json['ssl_hash'] ?? null) ? $json['ssl_hash'] : '';
    }

    /**
     * 批量把证书设到站点（v1）。
     * REF: certimate ssl.SetBatchCertToSite —— POST /ssl?action=SetBatchCertToSite，表单 BatchInfo(JSON)
     *
     * @param  list<string>  $siteNames
     */
    public function sslSetBatchCertToSite(string $sslHash, array $siteNames): void
    {
        $batchInfo = array_map(fn (string $siteName): array => [
            'siteName' => $siteName,
            'ssl_hash' => $sslHash,
        ], $siteNames);

        $this->postForm('/ssl?action=SetBatchCertToSite', [
            'BatchInfo' => (string) json_encode($batchInfo),
        ]);
    }

    /**
     * 上传证书到证书库（v2）。
     * REF: certimate v2.ssldomain.UploadCert —— POST /v2/ssl_domain?action=upload_cert，表单 key/cert
     *   → 响应 message.hash
     *
     * @return string ssl_hash
     */
    public function sslDomainUploadCertV2(string $certPEM, string $privkeyPEM): string
    {
        $json = $this->postForm('/v2/ssl_domain?action=upload_cert', [
            'key' => $privkeyPEM,
            'cert' => $certPEM,
        ], true);

        $message = is_array($json['message'] ?? null) ? $json['message'] : [];

        return is_string($message['hash'] ?? null) ? $message['hash'] : '';
    }

    /**
     * 把证书部署到站点（v2）。
     * REF: certimate v2.ssldomain.CertDeploySites —— POST /v2/ssl_domain?action=cert_deploy_sites，
     *   表单 hash/domains(JSON)/append
     *
     * @param  list<string>  $siteNames
     */
    public function sslDomainCertDeploySitesV2(string $sslHash, array $siteNames): void
    {
        $this->postForm('/v2/ssl_domain?action=cert_deploy_sites', [
            'hash' => $sslHash,
            'domains' => (string) json_encode($siteNames),
            'append' => '1',
        ], true);
    }

    /**
     * 设置面板自身 SSL 证书。
     * REF: certimate config.SavePanelSSL —— POST /config?action=SavePanelSSL，表单 privateKey/certPem
     */
    public function configSavePanelSSL(string $certPEM, string $privkeyPEM): void
    {
        $this->postForm('/config?action=SavePanelSSL', [
            'privateKey' => $privkeyPEM,
            'certPem' => $certPEM,
        ]);
    }

    /**
     * 管理系统服务（重启面板时用，宝塔重启会断连产生 error，调用方吞掉异常）。
     * REF: certimate system.ServiceAdmin —— POST /system?action=ServiceAdmin，表单 name/type
     */
    public function systemServiceAdmin(string $name, string $type): void
    {
        $this->postForm('/system?action=ServiceAdmin', [
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * 发起表单 POST、加签、归一错误。
     *
     * @param  array<string,string>  $form
     * @param  bool  $v2  v2 接口（status:int(0=成功)）；否则 v1（status:bool）
     * @return array<string,mixed>
     */
    private function postForm(string $path, array $form, bool $v2 = false): array
    {
        $timestamp = (string) time();
        $form['request_time'] = $timestamp;
        $form['request_token'] = md5($timestamp.md5($this->apiKey));

        // 相对路径去前导 /（base_uri 已是 serverUrl/），避免绝对路径替换 base
        $resp = $this->http->request('POST', ltrim($path, '/'), [
            'http_errors' => false,
            'form_params' => $form,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status < 200 || $status >= 300) {
            throw new BaotapanelApiException('BaotaError', "宝塔面板接口返回 HTTP $status");
        }

        if ($v2) {
            // v2：status:int，0 成功
            $st = $json['status'] ?? null;
            if ($st !== null && (int) $st !== 0) {
                throw new BaotapanelApiException('BaotaError', $this->extractV2Message($json));
            }
        } else {
            // v1：status:bool，false 失败
            $st = $json['status'] ?? null;
            if ($st === false || $st === 0 || $st === '0' || $st === 'false') {
                $msg = is_string($json['msg'] ?? null) && $json['msg'] !== '' ? $json['msg'] : '宝塔面板接口返回错误';
                throw new BaotapanelApiException('BaotaError', $msg);
            }
        }

        return $json;
    }

    /**
     * 抽取 v2 错误描述（message 可能是字符串或 {result}）。
     *
     * @param  array<string,mixed>  $json
     */
    private function extractV2Message(array $json): string
    {
        $message = $json['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }
        if (is_array($message) && is_string($message['result'] ?? null) && $message['result'] !== '') {
            return $message['result'];
        }

        return '宝塔面板接口返回错误';
    }
}
