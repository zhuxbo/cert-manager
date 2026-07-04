<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 华为云 CDN（证书服务型，全局）。
 *
 * 对齐 certimate deployer huaweicloud-cdn 的 exact 路径：
 *   1. 经 HuaweiScmUploader 把证书托管到 SCM 拿 certificate_id（store_kind=huawei_scm，走 RemoteCertStore 去重）。
 *   2. bind 调 CDN UpdateDomainMultiCertificates（PUT /v1.0/cdn/domains/config-https-info）：
 *      body {https:{domain_name, https_switch:1, certificate_type:2(SCM 托管), scm_certificate_id, cert_name}}。
 *
 * CDN 为**全局服务**（cdn.myhuaweicloud.com，global 凭证，无 region/projectId），对齐 certimate createSDKClient 用 global.NewCredentialsBuilder。
 * 企业项目 ID 作 enterprise_project_id 查询参数透传（非空才传，对齐 certimate lo.EmptyableToPtr）。
 *
 * 与 certimate 对齐的取舍：certimate 支持 domainMatchPattern（exact/wildcard/certsan）。本端点**仅实现 exact**
 * （domain 必填、精确匹配后单域名绑定），不做 wildcard/certsan 的「列举全部域名再泛/SAN 匹配」——与插件其他端点
 * 「仅 exact」口径一致。如需泛域名批量部署，可逐个域名各配一个 target。
 */
class CdnDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '华为云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // CDN 证书走 SCM 托管（region-less，回落 cn-north-4）。
        return new HuaweiScmUploader(
            fn (array $credentials): object => $this->makeClient('scm', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（SCM certificate_id）
     * @param  array<string,mixed>  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $scmCertId = (string) $certRef;
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        $enterpriseProjectId = isset($credentials['enterprise_project_id']) ? (string) $credentials['enterprise_project_id'] : '';

        $this->guardSdk(function () use ($credentials, $domain, $scmCertId, $certName, $enterpriseProjectId) {
            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            $query = [];
            if ($enterpriseProjectId !== '') {
                $query['enterprise_project_id'] = $enterpriseProjectId;
            }

            // UpdateDomainMultiCertificates：certificate_type=2 表示使用 SCM 托管证书（scm_certificate_id）。
            $client->put('/v1.0/cdn/domains/config-https-info', [
                'https' => [
                    'domain_name' => $domain,
                    'https_switch' => 1,
                    'certificate_type' => 2,
                    'scm_certificate_id' => $scmCertId,
                    'cert_name' => $certName,
                ],
            ], $query);
        });
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // CDN 全局服务（global 凭证，无 projectId）。
            'cdn' => new HuaweicloudRestClient(
                'cdn.myhuaweicloud.com',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            // SCM 托管走 region 服务（回落 cn-north-4）。
            'scm' => new HuaweicloudRestClient(
                $this->scmHost(),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
