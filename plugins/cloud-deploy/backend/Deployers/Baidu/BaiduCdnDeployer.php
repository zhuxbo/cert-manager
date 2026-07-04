<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 百度智能云 CDN（内联型）：CDN 支持 PutCert 直传 PEM（不经证书中心），故 usesRemoteCertStore=false、certUploader=null。
 *
 * 对齐 certimate baiducloud-cdn 的 updateDomainCertificate：调 BCE CDN REST
 *   PUT /v2/{domain}/certificates
 *   {certificate:{certName, certServerData, certPrivateData, certLinkData}, httpsEnable:"ON"}
 *
 * 与 certimate 对齐的取舍：certimate 支持 domainMatchPattern（exact/wildcard/certsan）。本端点**仅实现 exact**
 * 核心路径（domain 必填、直接对该域名 PutCert），不做 wildcard/certsan 的「列举全部 CDN 域名再匹配」——与插件其他
 * 端点（AliyunDcdn/EsaSaas 等）「仅 exact」的简化口径一致。如需泛域名按 SAN 批量部署，可逐个域名各配一个 target。
 */
class BaiduCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'baidu';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '百度智能云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $domain, $certName, $certRef) {
            /** @var BaiduRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $client->request('PUT', "/v2/$domain/certificates", [
                'certificate' => [
                    'certName' => $certName,
                    'certServerData' => trim($certRef['cert']),
                    'certPrivateData' => trim($certRef['key']),
                    'certLinkData' => trim($certRef['chain']),
                ],
                'httpsEnable' => 'ON',
            ], []);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // CDN 为 region-less endpoint，路径前缀 /v2。
            'cdn' => new BaiduRestClient('cdn.baidubce.com', $credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BaiduErrorSanitizer::sanitize($e);
    }
}
