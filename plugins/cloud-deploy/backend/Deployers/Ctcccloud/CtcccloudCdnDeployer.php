<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 天翼云 CDN（证书服务型，支持 exact/wildcard 域名匹配）。
 *
 * 对齐 certimate ctcccloud-cdn 的 Deploy（DOMAIN_MATCH_PATTERN_EXACT）：
 *   1. 先把证书创建到天翼云 CDN 证书空间拿 CertName（经 CtcccloudCertCreateUploader，store_kind=ctcccloud_cdn，
 *      POST /v1/cert/creat-cert，走 RemoteCertStore 去重）。
 *   2. bind 对 config.domain 精确匹配：
 *      - GET /v1/domain/query-domain-detail?domain={domain}（查询域名配置，确认域名存在 / 与 certimate 同序）。
 *      - POST /v1/domain/update-domain {domain, https_status:"on", cert_name=CertName}（绑定证书 + 开启 HTTPS）。
 *
 * wildcard 经 QueryDomainList 分页过滤后逐域名执行详情查询与更新；certsan 受服务型 bind 无 PEM 的共享契约限制。
 */
class CtcccloudCdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCtcccloudDomains;

    public function provider(): string
    {
        return 'ctcccloud';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '天翼云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new CtcccloudCertCreateUploader(
            fn (array $credentials): object => $this->makeClient('cdn', $credentials),
            'ctcccloud_cdn',
            '/v1/cert/creat-cert',
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（CDN CertName）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $certName = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $pattern, $certificate, $certName) {
            /** @var CtcccloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            foreach ($this->matchingDomains($client, '/v1/domain/query-domain-list', $domain, $pattern, certificate: $certificate) as $matchedDomain) {
                $client->get('/v1/domain/query-domain-detail', ['domain' => $matchedDomain]);
                $client->post('/v1/domain/update-domain', [
                    'domain' => $matchedDomain, 'https_status' => 'on', 'cert_name' => $certName,
                ]);
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new CtcccloudRestClient(
                'ctcdn-global.ctapi.ctyun.cn',
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
