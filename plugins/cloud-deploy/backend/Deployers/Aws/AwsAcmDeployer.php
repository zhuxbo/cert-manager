<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * AWS Certificate Manager（ACM，仅上传）。
 *
 * 对齐 certimate aws-acm：certificate_arn 留空时导入新证书，指定时原地替换同一 ARN；**不绑定任何资源**。
 * 适用「先托管证书到 ACM，后续在控制台/其他端点引用」场景。
 * 插件模型：usesRemoteCertStore=true + AwsAcmUploader（store_kind="acm:{region}"，region 隔离），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader($config)) 完成。
 */
class AwsAcmDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    use BuildsAwsClientConfig;

    public function provider(): string
    {
        return 'aws';
    }

    public function product(): string
    {
        return 'acm';
    }

    public function label(): string
    {
        return 'AWS Certificate Manager（仅上传）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_arn', 'label' => '证书 ARN（选填，指定时原地替换）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = (string) ($config['region'] ?? '');
        $certificateArn = (string) ($config['certificate_arn'] ?? '');

        return new AwsAcmUploader(
            fn (array $credentials): object => $this->makeClient('acm', $credentials, $region),
            $region,
            $certificateArn,
        );
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（CertificateArn，本端点不使用）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已导入 ACM，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cfg = $this->awsClientConfig($credentials, $region);

        return match ($kind) {
            'acm' => new AcmClient($cfg),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
