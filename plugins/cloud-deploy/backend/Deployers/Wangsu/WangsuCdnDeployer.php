<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 网宿云融合 CDN（证书服务型）。
 *
 * 流程对齐 certimate wangsu-cdn：
 *   1. 证书经网宿证书中心上传拿 certId（走 RemoteCertStore 去重，复用 WangsuCertUploader）。
 *   2. BatchUpdateCertificateConfig（PUT /api/config/certificate/batch）把 certId(int) 绑定到加速域名。
 *
 * 仅实现 exact domain 核心路径（对齐插件既有 cdn 约定）；不做 certimate 的多域名/wildcard 遍历。
 * exact 模式去掉域名前导 "*"（"*.example.com" → ".example.com"，适配网宿云 CDN 泛域名格式），
 * 与 certimate wangsu-cdn `strings.TrimPrefix(domain, "*")` 一致。
 */
class WangsuCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'wangsu';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '网宿云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 上传器复用 deployer 的注入缝：测试 override makeClient('api') 即作用于上传。
        return new WangsuCertUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（证书中心数字 certId）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        // exact：去掉前导 "*"，适配网宿云 CDN 泛域名格式（"*.example.com" → ".example.com"）。
        $domain = $domain === '' ? $domain : (string) preg_replace('/^\*/', '', $domain);
        $certId = (int) ((string) $certRef);

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var WangsuRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->batchUpdateCertificateConfig($certId, [$domain]);
        });
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
