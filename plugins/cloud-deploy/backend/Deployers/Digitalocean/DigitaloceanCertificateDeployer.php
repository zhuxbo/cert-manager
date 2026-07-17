<?php

namespace Plugins\CloudDeploy\Deployers\Digitalocean;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * DigitalOcean 证书（仅上传）。
 *
 * 对齐 certimate digitalocean-certificate：Deploy 只把证书上传到 DO 证书服务（POST /v2/certificates，
 * type=custom），不绑定资源（DO 的 Load Balancer 等通过证书 id 在控制台/其他流程引用）。
 *
 * 插件模型：usesRemoteCertStore=true + DigitaloceanCertUploader（storeKind=digitalocean_certificate，
 * RemoteCertStore 去重），bind 为 no-op —— 上传即部署。
 */
class DigitaloceanCertificateDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    private const BASE_URI = 'https://api.digitalocean.com/v2/';

    public function provider(): string
    {
        return 'digitalocean';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'DigitalOcean 证书（仅上传）';
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
        return new DigitaloceanCertUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（DO 证书 id，本端点不使用）
     * @param  array{access_token:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已上传到 DigitalOcean 证书服务，无资源绑定。
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new DigitaloceanClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.($credentials['access_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return DigitaloceanErrorSanitizer::sanitize($e);
    }
}
