<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 天翼云国际 CDN（ICDN，证书服务型，仅 exact 域名匹配）。
 *
 * 对齐 certimate ctcccloud-icdn 的 Deploy（DOMAIN_MATCH_PATTERN_EXACT）。接口形状与 CDN 一致、仅 endpoint 与
 * 证书空间不同：
 *   1. 创建证书到 ICDN 证书空间拿 CertName（store_kind=ctcccloud_icdn，POST /v1/cert/creat-cert）。
 *   2. bind 精确匹配 config.domain：
 *      - GET /v1/domain/query-domain-detail?domain={domain}。
 *      - POST /v1/domain/update-domain {domain, https_status:"on", cert_name=CertName}。
 *
 * endpoint host：icdn-global.ctapi.ctyun.cn（与 CDN 的 ctcdn-global 区分）。仅 exact（见 CtcccloudCdnDeployer 说明）。
 */
class CtcccloudIcdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'ctcccloud';
    }

    public function product(): string
    {
        return 'icdn';
    }

    public function label(): string
    {
        return '天翼云国际 CDN';
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
        return new CtcccloudCertCreateUploader(
            fn (array $credentials): object => $this->makeClient('icdn', $credentials),
            'ctcccloud_icdn',
            '/v1/cert/creat-cert',
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（ICDN CertName）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certName = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certName) {
            /** @var CtcccloudRestClient $client */
            $client = $this->makeClient('icdn', $credentials);

            $client->get('/v1/domain/query-domain-detail', ['domain' => $domain]);

            $client->post('/v1/domain/update-domain', [
                'domain' => $domain,
                'https_status' => 'on',
                'cert_name' => $certName,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'icdn' => new CtcccloudRestClient(
                'icdn-global.ctapi.ctyun.cn',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CtcccloudErrorSanitizer::sanitize($e);
    }
}
