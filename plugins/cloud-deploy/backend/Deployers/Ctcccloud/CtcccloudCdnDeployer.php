<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 天翼云 CDN（证书服务型，仅 exact 域名匹配）。
 *
 * 对齐 certimate ctcccloud-cdn 的 Deploy（DOMAIN_MATCH_PATTERN_EXACT）：
 *   1. 先把证书创建到天翼云 CDN 证书空间拿 CertName（经 CtcccloudCertCreateUploader，store_kind=ctcccloud_cdn，
 *      POST /v1/cert/creat-cert，走 RemoteCertStore 去重）。
 *   2. bind 对 config.domain 精确匹配：
 *      - GET /v1/domain/query-domain-detail?domain={domain}（查询域名配置，确认域名存在 / 与 certimate 同序）。
 *      - POST /v1/domain/update-domain {domain, https_status:"on", cert_name=CertName}（绑定证书 + 开启 HTTPS）。
 *
 * 与 certimate 的取舍：certimate 支持 exact/wildcard/certsan 三种 domainMatchPattern；本端点**仅 exact**
 * （domain 必填、精确匹配），不做 wildcard/certsan 的「分页列举全部域名再泛/SAN 匹配」—— 与插件其他 CDN 类端点
 * （AliyunCdn/Qiniu/Baidu 等）口径一致。如需泛域名按 SAN 批量部署，可逐个域名各配一个 target。
 */
class CtcccloudCdnDeployer extends AbstractDeployer
{
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
        $domain = (string) $this->requireConfig($config, 'domain');
        $certName = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certName) {
            /** @var CtcccloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            // 查询域名配置（与 certimate 同序，确认域名存在）。
            $client->get('/v1/domain/query-domain-detail', ['domain' => $domain]);

            // 绑定证书并开启 HTTPS。
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
