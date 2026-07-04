<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 天翼云证书管理服务 CMS（仅上传）。
 *
 * 对齐 certimate ctcccloud-cms：Deploy 只把证书托管到天翼云 CMS，**不绑定任何资源**。适用于「先托管证书到云证书
 * 服务，后续在控制台/其他端点引用」的场景。
 *
 * 插件模型：usesRemoteCertStore=true + 复用 CtcccloudCmsUploader（store_kind=ctcccloud_cms，RemoteCertStore
 * 去重），bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader) 完成（同 TencentSslDeployer /
 * BaiduCertDeployer / KsyunKcmDeployer）。
 *
 * certUploader 空 config 不抛（CMS 全局、无 region 依赖）—— 元信息探测 / catalog 渲染时 config 为空也能构造上传器。
 */
class CtcccloudCmsDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'ctcccloud';
    }

    public function product(): string
    {
        return 'cms';
    }

    public function label(): string
    {
        return '天翼云证书管理 CMS（仅上传）';
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
        return new CtcccloudCmsUploader(fn (array $credentials): object => $this->makeClient('cms', $credentials));
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（CMS 证书标识，本端点不使用）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已托管到天翼云 CMS，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cms' => new CtcccloudRestClient(
                'ccms-global.ctapi.ctyun.cn',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                ['200'],   // CMS 成功码 200
                true,      // CMS error 非空即失败（无放行值）
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CtcccloudErrorSanitizer::sanitize($e);
    }
}
