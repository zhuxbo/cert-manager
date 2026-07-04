<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 京东云 SSL 证书中心（纯上传，bind no-op）。
 *
 * 对齐 certimate jdcloud-ssl：仅把证书上传到京东云 SSL 证书中心（走 RemoteCertStore 去重），
 * 不绑定任何资源。usesRemoteCertStore=true + 复用 JdcloudSslUploader（storeKind=jdcloud_ssl）。
 */
class JdcloudSslDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'jdcloud';
    }

    public function product(): string
    {
        return 'ssl';
    }

    public function label(): string
    {
        return '京东云 SSL 证书';
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
        return new JdcloudSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * bind no-op：证书已由 RemoteCertStore::ensure 经 certUploader 上传到 SSL 证书中心，无后续绑定。
     *
     * @param  string|array{cert:string,key:string,chain:string}  $certRef  云端 certId（纯上传不使用）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：纯上传，证书中心已收录。
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'ssl' => JdcloudClientFactory::ssl($credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}
