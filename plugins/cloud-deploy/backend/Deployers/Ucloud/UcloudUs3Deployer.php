<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 优刻得 对象存储（US3 / UFile）部署器 —— 证书服务型（usesRemoteCertStore=true，USSL）。
 *
 * 流程对齐 certimate ucloud-us3：
 *   1. 证书经 USSL 上传拿 CertificateID（走 RemoteCertStore 去重，USSL 全局空间不分 region）。
 *   2. AddUFileSSLCert {BucketName, Domain, CertificateName, USSLId=数字 certId} 给存储桶自定义域名绑证书。
 *
 * region：UFile 是区域型服务（创建 client 时设 Region），与 USSL 上传（全局）解耦——故上传器
 * storeKind 仍为 ucloud_ussl（全局），bind 时的 client 携带 region。仅 exact 域名核心路径（不支持泛域名）。
 */
class UcloudUs3Deployer extends AbstractDeployer
{
    use ParsesUcloudCertRef;
    use UcloudClientFactory;

    public function provider(): string
    {
        return 'ucloud';
    }

    public function product(): string
    {
        return 'us3';
    }

    public function label(): string
    {
        return '优刻得 对象存储 US3';
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
        // USSL 上传全局（不分 region），用 region-less client（makeClient 默认 region=''）；
        // 测试 override makeClient('api') 即作用于上传。
        return new UcloudUsslUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * @param  string  $certRef  复合 remote_cert_id "{certId}|{certName}"（US3 用 certId + certName）
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @param  array{region:string,bucket:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $domain = (string) $this->requireConfig($config, 'domain');
        [$certIdStr, $certName] = $this->parseCertRef((string) $certRef);

        $this->guardSdk(function () use ($credentials, $region, $bucket, $domain, $certIdStr, $certName) {
            /** @var UcloudRestClient $client */
            $client = $this->makeClient('api', $credentials, $region);
            $client->addUFileSSLCert($bucket, $domain, $certName, $certIdStr);
        });
    }

    /**
     * 区域型注入缝：region 取自 config（默认空，供上传器 region-less 复用）。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'api' => $this->buildUcloudClient($credentials, $region),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return UcloudErrorSanitizer::sanitize($e);
    }
}
