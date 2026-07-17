<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * 金山云证书管理 KCM（仅上传）。
 *
 * 对齐 certimate ksyun-kcm：Deploy 只把证书托管到金山云 KCM（拿 CertId），**不绑定任何资源**。
 * 适用于「先托管证书到云证书服务，后续在控制台/其他端点引用」的场景。
 *
 * 插件模型：usesRemoteCertStore=true + 复用 KsyunKcmUploader（store_kind=ksyun_kcm，RemoteCertStore
 * 去重），bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader) 完成
 * （同 TencentSslDeployer / BaiduCertDeployer）。
 */
class KsyunKcmDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    public function provider(): string
    {
        return 'ksyun';
    }

    public function product(): string
    {
        return 'kcm';
    }

    public function label(): string
    {
        return '金山云证书管理 KCM（仅上传）';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new KsyunKcmUploader(fn (array $credentials): object => $this->makeClient('kcm', $credentials));
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（KCM CertId，本端点不使用）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已托管到金山云 KCM，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'kcm' => new KsyunRestClient(
                'kcm',
                'kcm.api.ksyun.com',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return KsyunErrorSanitizer::sanitize($e);
    }
}
