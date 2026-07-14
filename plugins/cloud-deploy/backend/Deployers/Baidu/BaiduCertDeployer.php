<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * 百度智能云证书中心（仅上传）。
 *
 * 对齐 certimate baiducloud-cert：Deploy 只把证书上传到百度证书中心（拿 certId），**不绑定任何资源**。
 * 适用于「先托管证书到云证书服务，后续在控制台/其他端点引用」的场景。
 *
 * 插件模型：usesRemoteCertStore=true + 复用 BaiduCertUploader（store_kind=baidu_cert，RemoteCertStore
 * 去重），bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader) 完成（同 TencentSslDeployer）。
 */
class BaiduCertDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    public function provider(): string
    {
        return 'baidu';
    }

    public function product(): string
    {
        return 'cert';
    }

    public function label(): string
    {
        return '百度智能云证书中心（仅上传）';
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
        return new BaiduCertUploader(fn (array $credentials): object => $this->makeClient('cert', $credentials));
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（certId，本端点不使用）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已上传到百度证书中心，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // 证书中心为 region-less endpoint。
            'cert' => new BaiduRestClient('certificate.baidubce.com', $credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BaiduErrorSanitizer::sanitize($e);
    }
}
