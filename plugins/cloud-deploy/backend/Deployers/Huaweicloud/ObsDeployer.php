<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Throwable;

/**
 * 华为云对象存储 OBS 自定义域名（证书服务型，region 维度）。
 *
 * 对齐 certimate deployer huaweicloud-obs：
 *   1. 经 HuaweiScmUploader 把证书托管到 SCM 拿 certificate_id（store_kind=huawei_scm，走 RemoteCertStore 去重）。
 *   2. bind 调 OBS PutBucketCustomDomain（PUT /?customdomain={domain}，XML body {Name, CertificateId}）为存储桶自定义域名绑证书。
 *
 * OBS 用**独立**的 OBS V2 签名（HMAC-SHA1），不同于其余华为云服务的 SDK-HMAC-SHA256 —— 见 HuaweiObsClient。
 * host 为虚拟主机式 {bucket}.obs.{region}.myhuaweicloud.com。region + bucket + domain 均必填。
 *
 * 与 certimate 的偏差：certimate PutBucketCustomDomain 同时传 CertificateId + 内联 PEM；本实现仅传 CertificateId
 * （引用 SCM 托管证书，OBS 自动取内容），因证书服务型 bind 不携带 PEM（CloudDeployJob 契约）。详见 HuaweiObsClient。
 */
class ObsDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'obs';
    }

    public function label(): string
    {
        return '华为云对象存储 OBS';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'bucket', 'label' => '存储桶名', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // OBS 证书走 SCM 托管（region-less，回落 cn-north-4；certimate certmgr 用空 region）。
        return new HuaweiScmUploader(
            fn (array $credentials): object => $this->makeClient('scm', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（SCM certificate_id）
     * @param  array<string,mixed>  $credentials
     * @param  array{region:string,bucket:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $domain = (string) $this->requireConfig($config, 'domain');
        $scmCertId = (string) $certRef;
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $region, $bucket, $domain, $scmCertId, $certName) {
            /** @var HuaweiObsClient $client */
            $client = $this->makeClient('obs', $credentials, $region, $bucket);
            $client->putBucketCustomDomain($domain, $certName, $scmCertId);
        });
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = '', string $bucket = ''): object
    {
        return match ($kind) {
            // OBS 独立签名（HMAC-SHA1），虚拟主机式 host；bucket 可经 :port/ 注入突破 DNS 后缀（反模式 18）
            'obs' => $this->newObsClient($credentials, $region, $bucket),
            // SCM 托管走 SDK-HMAC-SHA256 REST（回落 cn-north-4）。
            'scm' => new HuaweicloudRestClient(
                $this->scmHost(),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    private function newObsClient(array $credentials, string $region, string $bucket): HuaweiObsClient
    {
        app(OutboundDestinationPolicy::class)->authorizeOfficialHost(
            $this->provider(),
            $bucket.'.obs.'.$region.'.myhuaweicloud.com',
        );

        return new HuaweiObsClient(
            $bucket,
            $region,
            $credentials['access_key_id'] ?? '',
            $credentials['secret_access_key'] ?? '',
        );
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
