<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * AWS Certificate Manager（ACM，仅上传）。
 *
 * 对齐 certimate aws-acm 的「新建证书」分支：Deploy 把证书导入 ACM（拿 CertificateArn），**不绑定任何资源**。
 * 适用「先托管证书到 ACM，后续在控制台/其他端点引用」场景。
 * （certimate 的 CertificateArn 非空时走「替换」——本插件靠 RemoteCertStore 按指纹去重 + 续期上传新证书，
 *  不实现原地替换，故省略 certificateArn 配置。）
 *
 * 插件模型：usesRemoteCertStore=true + AwsAcmUploader（store_kind="acm:{region}"，region 隔离），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader($config)) 完成。
 */
class AwsAcmDeployer extends AbstractDeployer
{
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
        return match ($kind) {
            'acm' => new AcmClient([
                'version' => 'latest',
                'region' => $region !== '' ? $region : 'us-east-1',
                'credentials' => [
                    'key' => $credentials['access_key_id'] ?? '',
                    'secret' => $credentials['secret_access_key'] ?? '',
                ],
            ]),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
