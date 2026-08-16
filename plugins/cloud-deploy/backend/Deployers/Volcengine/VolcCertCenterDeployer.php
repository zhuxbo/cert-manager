<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * 火山引擎证书中心（Certificate Service）：纯上传到证书中心，bind no-op。
 * 对齐 certimate volcengine-certcenter（Deploy 仅 Upload 一步，无后续资源绑定）。
 *
 * 用途：把证书托管到火山证书中心备用（其他端点也会自动上传，但本端点供「只上传不绑定」场景）。
 * usesRemoteCertStore=true + 复用 VolcCertCenterUploader（storeKind=volc_certcenter）；bind 收 InstanceId 但不做事。
 * region 默认 cn-beijing（证书中心默认区域，与 certimate 一致）。
 */
class VolcCertCenterDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    use ResolvesVolcRegion;

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'certcenter';
    }

    public function label(): string
    {
        return '火山引擎证书中心';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域（默认 cn-beijing）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = $this->resolveRegion($config, 'cn-beijing');

        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * 纯上传端点：证书已由 RemoteCertStore 经 certUploader 上传到证书中心，bind 无需再做任何事。
     *
     * @param  string  $certRef  证书中心 InstanceId（此端点不使用）
     * @param  array<string,mixed>  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：上传即完成（与 Aliyun cas / Tencent ssl / Baidu cert 一致）
    }

    protected function makeClient(string $kind, array $credentials, string $region = 'cn-beijing'): object
    {
        return match ($kind) {
            'certcenter' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'certificate_service',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}
