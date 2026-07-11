<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 SSL 证书服务（CAS，仅上传）。
 *
 * 对齐 certimate aliyun-cas：Deploy 只把证书上传到 CAS 证书服务（拿 CertIdentifier），**不绑定任何资源**。
 * 适用于「先把证书托管到云证书服务，后续在控制台/其他端点引用」的场景。
 *
 * 插件模型：usesRemoteCertStore=true + 复用 AliyunCasUploader（store_kind=cas，RemoteCertStore 去重），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader) 完成，本端点无后续绑定。
 */
class AliyunCasDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'cas';
    }

    public function label(): string
    {
        return '阿里云 SSL 证书服务（仅上传）';
    }

    public function configSchema(): array
    {
        // 纯上传无资源配置。
        return [];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // CAS 全局，上传不需要 region；复用 deployer 注入缝
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（CertIdentifier，本端点不使用）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已上传到 CAS，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, 'cas.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
