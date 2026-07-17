<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 京东云直播 Live（内联型，直灌 PEM）：直接把证书 PEM + 私钥设到直播流域名，不经 SSL 证书中心。
 *
 * 对齐 certimate jdcloud-live exact 路径：
 *   SetLiveDomainCertificate（POST /v1/liveDomainCertificate，
 *     body {playDomain, certStatus=on, cert, key}）
 *
 * 直播流域名不支持泛域名，仅 exact（对齐 certimate）；不做 certsan 遍历（DescribeLiveDomains）。
 * usesRemoteCertStore=false → bind 收 {cert,key,chain} 三元组（cert 拼完整链，与上传器一致）。
 */
class JdcloudLiveDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'jdcloud';
    }

    public function product(): string
    {
        return 'live';
    }

    public function label(): string
    {
        return '京东云直播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  string|array{cert:string,key:string,chain:string}  $certRef  内联 PEM 三元组
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        if (! is_array($certRef)) {
            $this->fail('京东云直播为内联型，需 PEM 三元组');
        }
        // 证书 + 中间证书拼完整链（与上传器/其他内联端点一致）
        $certPem = rtrim((string) ($certRef['cert'] ?? ''))."\n".trim((string) ($certRef['chain'] ?? ''));
        $keyPem = (string) ($certRef['key'] ?? '');

        $this->guardSdk(function () use ($credentials, $domain, $certPem, $keyPem) {
            /** @var JdcloudRestClient $client */
            $client = $this->makeClient('live', $credentials);
            $client->setLiveDomainCertificate($domain, trim($certPem), trim($keyPem));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'live' => JdcloudClientFactory::live($credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}
