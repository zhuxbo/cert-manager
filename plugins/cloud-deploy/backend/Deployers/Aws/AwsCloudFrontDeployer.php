<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Aws\CloudFront\CloudFrontClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * AWS CloudFront（内容分发，证书服务型）：证书先经 ACM 上传拿 CertificateArn（走 RemoteCertStore 去重），
 * 再 GetDistributionConfig → 改 ViewerCertificate → UpdateDistribution 把证书关联到指定分发。
 *
 * 对齐 certimate aws-cloudfront 的 **ACM 源**：
 *   1. GetDistributionConfig(Id) → 取 DistributionConfig + ETag。
 *   2. 设 ViewerCertificate.CloudFrontDefaultCertificate=false + ACMCertificateArn=ARN。
 *   3. UpdateDistribution(Id, DistributionConfig, IfMatch=ETag)。
 *
 * **CloudFront 证书强制在 us-east-1**（CloudFront 全球分发只认 us-east-1 的 ACM 证书）——由 config.region 体现，
 * 用户须填 us-east-1（ACM 上传器据 config.region 上传到该 region，storeKind="acm:us-east-1" 隔离）。
 *
 * 偏差说明：certimate 还支持 IAM 源（ViewerCertificate.IAMCertificateId = **ServerCertificateId**，非 ARN）。
 * 本插件 remote_cert_id 单值契约统一返回 **ARN**，与 IAMCertificateId 所需的 ServerCertificateId 不同一标识，
 * 无法在单值契约下兼得；且 CloudFront 用 IAM 证书是 legacy（ACM 为官方推荐路径）。故本端点**仅 ACM 源**，
 * 不实现 IAM 源（需要 IAM 源者用 aws/iam 端点上传 + 控制台绑定）。
 */
class AwsCloudFrontDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'aws';
    }

    public function product(): string
    {
        return 'cloudfront';
    }

    public function label(): string
    {
        return 'AWS CloudFront';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域（须 us-east-1）', 'type' => 'string', 'required' => true],
            ['key' => 'distribution_id', 'label' => '分发 ID', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = (string) ($config['region'] ?? '');

        return new AwsAcmUploader(
            fn (array $credentials): object => $this->makeClient('acm', $credentials, $region),
            $region,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（ACM CertificateArn）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{region:string,distribution_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $distributionId = (string) $this->requireConfig($config, 'distribution_id');
        $certificateArn = (string) $certRef;

        // CloudFront 是全球服务，client region 不影响（SDK 固定走全球 endpoint）；仍按 config.region 构造。
        /** @var CloudFrontClient $client */
        $client = $this->makeClient('cloudfront', $credentials, $region);

        // 取分发配置 + ETag（SDK 在 guardSdk 内、business 判定在外）
        $current = $this->guardSdk(fn () => $client->getDistributionConfig(['Id' => $distributionId]));
        $distConfig = $current['DistributionConfig'] ?? null;
        $etag = $current['ETag'] ?? null;
        if (! is_array($distConfig) || ! is_string($etag) || $etag === '') {
            $this->fail("未找到 CloudFront 分发: $distributionId");
        }

        // 设 ViewerCertificate（ACM 源）：关闭默认证书、绑 ACMCertificateArn、清空 IAMCertificateId
        $viewer = is_array($distConfig['ViewerCertificate'] ?? null) ? $distConfig['ViewerCertificate'] : [];
        $viewer['CloudFrontDefaultCertificate'] = false;
        $viewer['ACMCertificateArn'] = $certificateArn;
        unset($viewer['IAMCertificateId']);
        $distConfig['ViewerCertificate'] = $viewer;

        $this->guardSdk(fn () => $client->updateDistribution([
            'Id' => $distributionId,
            'DistributionConfig' => $distConfig,
            'IfMatch' => $etag,
        ]));
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cfg = [
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'us-east-1',
            'credentials' => [
                'key' => $credentials['access_key_id'] ?? '',
                'secret' => $credentials['secret_access_key'] ?? '',
            ],
        ];

        return match ($kind) {
            'acm' => new AcmClient($cfg),
            'cloudfront' => new CloudFrontClient($cfg),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
