<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * 网宿云证书中心（仅上传）。
 *
 * 对齐 certimate wangsu-certificate：Deploy 把证书托管到网宿证书中心（拿 certId），**不绑定任何资源**。
 * 适用于「先托管证书到云证书服务，后续在控制台/其他端点引用」的场景。
 *
 * 插件模型：usesRemoteCertStore=true + 复用 WangsuCertUploader（store_kind=wangsu_certificate，
 * RemoteCertStore 去重），bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader)
 * 完成（同 TencentSslDeployer / BaiduCertDeployer / DigitaloceanCertificateDeployer）。
 *
 * 可选 certificate_id 对齐 certimate 的原位替换语义；未填写时仍由 RemoteCertStore 按指纹去重并新建。
 */
class WangsuCertificateDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    public function provider(): string
    {
        return 'wangsu';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return '网宿云证书中心（仅上传）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '证书 ID（留空则新建）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new WangsuCertUploader(
            fn (array $credentials): object => $this->makeClient('api', $credentials),
            (string) ($config['certificate_id'] ?? ''),
        );
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（certId，本端点不使用）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已上传到网宿证书中心，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new WangsuRestClient(
                $credentials['access_key_id'] ?? '',
                $credentials['access_key_secret'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return WangsuErrorSanitizer::sanitize($e);
    }
}
