<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * BytePlus 证书中心（仅上传）。
 *
 * 对齐 certimate byteplus-certcenter：Deploy 只把证书上传到 BytePlus 证书中心（拿证书 id），
 * **不绑定任何资源**。适用于「先托管证书到云证书服务，后续在控制台/其他端点引用」的场景。
 *
 * 插件模型：usesRemoteCertStore=true + 复用 BytePlusCertCenterUploader（store_kind=byteplus_certcenter，
 * RemoteCertStore 去重），bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader) 完成。
 *
 * region：证书中心默认 ap-singapore-1（新加坡，对齐 certimate createSDKClient 默认）；用户可在 config 指定。
 * certUploader 据 config.region 构造对应签名 region 的 client（与阿里 SLB 按 region 构造同思路）。
 */
class BytePlusCertCenterDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    use ResolvesBytePlusRegion;

    /** 证书中心默认签名 region（对齐 certimate）。 */
    private const DEFAULT_CERTCENTER_REGION = 'ap-singapore-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'certcenter';
    }

    public function label(): string
    {
        return 'BytePlus 证书中心（仅上传）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域（选填，默认 ap-singapore-1）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = $this->resolveRegion($config, self::DEFAULT_CERTCENTER_REGION);

        return new BytePlusCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（证书中心 id，本端点不使用）
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已上传到 BytePlus 证书中心，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'certcenter' => new BytePlusRestClient(
                'certificate_service',
                $region !== '' ? $region : self::DEFAULT_CERTCENTER_REGION,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BytePlusErrorSanitizer::sanitize($e);
    }
}
