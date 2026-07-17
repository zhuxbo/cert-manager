<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 火山引擎 CDN（证书服务型）：证书经火山 CDN 自有证书空间上传拿 CertId（走 RemoteCertStore 去重），
 * 再把 CertId 关联到加速域名。对齐 certimate volcengine-cdn：
 *   BatchDeployCert {Domain, CertId}（Action=BatchDeployCert, Version=2021-03-01）
 *
 * 仅实现 exact domain 核心路径（对齐插件既有约定）；不做 certimate 的 wildcard/certsan 遍历。
 * CDN 为 region-less，签名 region 固定 cn-north-1（与 certimate 一致），故凭证里的 region 不参与。
 */
class VolcCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '火山引擎 CDN';
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
        // CDN 走自有证书空间（VolcCdnUploader），非证书中心。
        return new VolcCdnUploader(fn (array $credentials): object => $this->makeClient('cdn', $credentials));
    }

    /**
     * @param  string  $certRef  CDN CertId
     * @param  array{access_key_id?:string,secret_access_key?:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $client->callJson('BatchDeployCert', '2021-03-01', [
                'Domain' => $domain,
                'CertId' => $certId,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // CDN 通用网关，region-less → 固定 cn-north-1（对齐 certimate）
            'cdn' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'cdn',
                'cn-north-1',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}
