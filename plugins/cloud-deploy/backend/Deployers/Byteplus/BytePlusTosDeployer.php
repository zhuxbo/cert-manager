<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Throwable;

/**
 * BytePlus 对象存储 TOS（证书服务型）：证书先经**证书中心** UploadCertificate 上传拿 id（走 RemoteCertStore
 * 去重），再 PutBucketCustomDomain 把证书绑到 TOS 桶的自定义域名。
 *
 * 对齐 certimate byteplus-tos：
 *   - 上传：证书中心 UploadCertificate（region 固定 ap-singapore-1）→ CertId（复用 byteplus-certcenter certmgr）。
 *   - 绑定：TOS PutBucketCustomDomain{CustomDomainRule:{Domain, CertId}}（S3 风格 TOS4 签名，PUT /?customdomain）。
 *
 * 两套签名分两个 client kind：
 *   - 'certcenter'（OpenAPI HMAC-SHA256，host open.byteplusapi.com，region ap-singapore-1）—— 上传。
 *   - 'tos'（TOS4-HMAC-SHA256，host {bucket}.tos-{region}.bytepluses.com，service tos）—— 绑定。
 *
 * TOS 自定义域名不支持泛域名（对齐 certimate）；仅 exact domain。
 */
class BytePlusTosDeployer extends AbstractDeployer
{
    use ResolvesBytePlusRegion;

    /** TOS 证书上传走证书中心，固定 ap-singapore-1（对齐 certimate）。 */
    private const CERTCENTER_REGION = 'ap-singapore-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'tos';
    }

    public function label(): string
    {
        return 'BytePlus 对象存储 TOS';
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
        // 证书上传走证书中心（region-less 维度，固定 ap-singapore-1），与 TOS 桶 region 无关。
        return new BytePlusCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（证书中心 CertId）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array{region:string,bucket:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $bucket, $domain, $certId) {
            /** @var BytePlusRestClient $client */
            $client = $this->makeClient('tos', $credentials, $region, $bucket);
            // 设置自定义域名：PUT /?customdomain body {CustomDomainRule:{Domain, CertId}}
            $client->tosPut('/?customdomain', [
                'CustomDomainRule' => [
                    'Domain' => $domain,
                    'CertId' => $certId,
                ],
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = '', string $bucket = ''): object
    {
        return match ($kind) {
            // 证书中心：OpenAPI 签名，统一网关 host，固定 ap-singapore-1。
            'certcenter' => new BytePlusRestClient(
                'certificate_service',
                self::CERTCENTER_REGION,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            // TOS：S3 风格 TOS4 签名，host 含 bucket + region。
            'tos' => $this->newTosClient($credentials, $region, $bucket),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    /**
     * bucket 是租户可控字段，可经 :port/ 注入突破 DNS 后缀直连内网（反模式 18）。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function newTosClient(array $credentials, string $region, string $bucket): BytePlusRestClient
    {
        $host = "$bucket.tos-$region.bytepluses.com";
        app(OutboundDestinationPolicy::class)->authorizeOfficialHost($this->provider(), $host);

        return new BytePlusRestClient(
            'tos',
            $region,
            $credentials['access_key_id'] ?? '',
            $credentials['secret_access_key'] ?? '',
            $host,
        );
    }

    protected function sanitize(Throwable $e): string
    {
        return BytePlusErrorSanitizer::sanitize($e);
    }
}
