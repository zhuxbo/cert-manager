<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * 华为云 SCM 证书管理（仅上传）。
 *
 * 对齐 certimate deployer huaweicloud-scm：Deploy 只把证书托管到华为云 SCM（拿 certificate_id），**不绑定任何资源**。
 * 适用于「先托管证书到云证书服务，后续在控制台/其他端点引用」的场景。
 *
 * 插件模型：usesRemoteCertStore=true + 复用 HuaweiScmUploader（store_kind=huawei_scm，RemoteCertStore 去重），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader) 完成（同 TencentSslDeployer /
 * BaiduCertDeployer / KsyunKcmDeployer）。
 *
 * 证书服务型在空 config 探活时不抛：region 用 `$config['region'] ?? ''` 优雅默认（回落 cn-north-4），真实 region 由 config 注入。
 */
class ScmDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'scm';
    }

    public function label(): string
    {
        return '华为云 SCM（仅上传）';
    }

    public function configSchema(): array
    {
        // SCM 证书托管为全局（默认 cn-north-4）；region 选填，仅决定上传 endpoint 区域。
        return [
            ['key' => 'region', 'label' => '地域（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 空 config 探活时 region 优雅默认（回落 cn-north-4），不抛；企业项目由 uploader 从凭证读取。
        $region = isset($config['region']) ? (string) $config['region'] : '';

        return new HuaweiScmUploader(
            fn (array $credentials): object => $this->makeClient('scm', $credentials, $region),
        );
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（SCM certificate_id，本端点不使用）
     * @param  array<string,mixed>  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已托管到华为云 SCM，无资源绑定。
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'scm' => new HuaweicloudRestClient(
                $this->scmHost($region),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
