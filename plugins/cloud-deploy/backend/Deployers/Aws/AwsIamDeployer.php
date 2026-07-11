<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Iam\IamClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * AWS IAM 服务器证书（仅上传）。
 *
 * 对齐 certimate aws-iam：Deploy 把证书上传到 IAM 服务器证书（拿 Arn），**不绑定任何资源**。
 * 适用「需要 IAM 服务器证书（如传统 ELB / CloudFront IAM 源）但绑定在控制台或其他端点完成」场景。
 * IAM 是全局服务（非 region），certificatePath 可选（缺省 "/"，对齐 certimate 默认）。
 *
 * 插件模型：usesRemoteCertStore=true + AwsIamUploader（store_kind="iam"），bind 为 no-op ——
 * 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader($config)) 完成。
 */
class AwsIamDeployer extends AbstractDeployer
{
    use BuildsAwsClientConfig;

    public function provider(): string
    {
        return 'aws';
    }

    public function product(): string
    {
        return 'iam';
    }

    public function label(): string
    {
        return 'AWS IAM 服务器证书（仅上传）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_path', 'label' => '证书路径', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = (string) ($config['region'] ?? '');
        $path = (string) ($config['certificate_path'] ?? '/');

        return new AwsIamUploader(
            fn (array $credentials): object => $this->makeClient('iam', $credentials, $region),
            $path !== '' ? $path : '/',
        );
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（IAM 服务器证书 Arn，本端点不使用）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已上传 IAM，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        // IAM 是全局服务，但 SDK 仍要求 region；缺省回落 us-east-1（aws-global 等价）。
        $cfg = $this->awsClientConfig($credentials, $region);

        return match ($kind) {
            'iam' => new IamClient($cfg),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
