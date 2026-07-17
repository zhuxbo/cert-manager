<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Aws\Amplify\AmplifyClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * AWS Amplify（前端托管，证书服务型）：证书先经 ACM 上传拿 CertificateArn（走 RemoteCertStore 去重），
 * 再调 Amplify.UpdateDomainAssociation 把自定义证书绑到 App 的自定义域名。
 *
 * 对齐 certimate aws-amplify（**仅 ACM 源**）：
 *   UpdateDomainAssociation(appId, domainName, certificateSettings={type:CUSTOM, customCertificateArn:ARN})
 *
 * 注意 Amplify SDK 入参为**小驼峰** member 名（appId/domainName/certificateSettings.type/customCertificateArn），
 * 写错大小写会静默丢值。CertificateType 枚举取 "CUSTOM"。Amplify 不支持泛域名（对齐 certimate 注释）。
 *
 * 简化：仅绑定主域名证书，不处理 subDomainSettings。
 */
class AwsAmplifyDeployer extends AbstractDeployer
{
    use BuildsAwsClientConfig;

    public function provider(): string
    {
        return 'aws';
    }

    public function product(): string
    {
        return 'amplify';
    }

    public function label(): string
    {
        return 'AWS Amplify';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'app_id', 'label' => 'Amplify 应用 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名（不支持泛域名）', 'type' => 'string', 'required' => true],
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
     * @param  array{region:string,app_id:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $appId = (string) $this->requireConfig($config, 'app_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certificateArn = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $appId, $domain, $certificateArn) {
            /** @var AmplifyClient $client */
            $client = $this->makeClient('amplify', $credentials, $region);
            $client->updateDomainAssociation([
                'appId' => $appId,
                'domainName' => $domain,
                'certificateSettings' => [
                    'type' => 'CUSTOM',
                    'customCertificateArn' => $certificateArn,
                ],
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cfg = $this->awsClientConfig($credentials, $region);

        return match ($kind) {
            'acm' => new AcmClient($cfg),
            'amplify' => new AmplifyClient($cfg),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
