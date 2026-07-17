<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 京东云 CDN（证书服务型）：证书先经 SSL 证书中心上传拿 certId（走 RemoteCertStore 去重），
 * 再为加速域名设置 HTTPS 协议并绑定该 certId。
 *
 * 对齐 certimate jdcloud-cdn exact 路径：
 *   QueryDomainConfig（GET /v1/domain/{domain}/config，取 httpsJumpType）
 *   → SetHttpType（POST /v1/domain/{domain}/httpType，httpType=https / certFrom=ssl / sslCertId=certId /
 *     jumpType=沿用查询到的 httpsJumpType）
 *
 * 仅实现 exact domain 核心路径（对齐插件既有约定）；不做 certimate 的 wildcard / certsan 遍历
 * （GetDomainList + DomainMatchPattern）。
 */
class JdcloudCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'jdcloud';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '京东云 CDN';
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
        return new JdcloudSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  云端 certId（SSL 证书中心上传所得）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var JdcloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $jumpType = $client->queryCdnDomainHttpsJumpType($domain);
            $client->setCdnHttpType($domain, $certId, $jumpType);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'ssl' => JdcloudClientFactory::ssl($credentials),
            'cdn' => JdcloudClientFactory::cdn($credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}
