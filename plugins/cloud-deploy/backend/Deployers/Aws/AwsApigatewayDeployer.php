<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Aws\ApiGatewayV2\ApiGatewayV2Client;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * AWS API Gateway（API 网关，证书服务型）：证书先经 ACM 上传拿 CertificateArn（走 RemoteCertStore 去重），
 * 再调 ApiGatewayV2.UpdateDomainName 把证书绑到自定义域名。
 *
 * 对齐 certimate aws-apigateway（**仅 ACM 源**，用 apigatewayv2 client）：
 *   UpdateDomainName(DomainName, DomainNameConfigurations=[{CertificateArn:ARN}])
 *
 * API 网关支持泛域名（对齐 certimate 注释）。简化：仅替换第一段 DomainNameConfiguration 的证书。
 */
class AwsApigatewayDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'aws';
    }

    public function product(): string
    {
        return 'apigateway';
    }

    public function label(): string
    {
        return 'AWS API Gateway';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
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
     * @param  array{region:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certificateArn = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $domain, $certificateArn) {
            /** @var ApiGatewayV2Client $client */
            $client = $this->makeClient('apigatewayv2', $credentials, $region);
            $client->updateDomainName([
                'DomainName' => $domain,
                'DomainNameConfigurations' => [
                    ['CertificateArn' => $certificateArn],
                ],
            ]);
        });
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
            'apigatewayv2' => new ApiGatewayV2Client($cfg),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
