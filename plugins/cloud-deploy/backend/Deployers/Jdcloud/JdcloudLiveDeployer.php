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
    use MatchesJdcloudCertificateDomains;

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
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  string|array{cert:string,key:string,chain:string}  $certRef  内联 PEM 三元组
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        if (! is_array($certRef)) {
            $this->fail('京东云直播为内联型，需 PEM 三元组');
        }
        // 证书 + 中间证书拼完整链（与上传器/其他内联端点一致）
        $certPem = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $keyPem = $certRef['key'];

        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        /** @var JdcloudRestClient $client */
        $client = $this->makeClient('live', $credentials);
        $domains = match ($pattern) {
            '', 'exact' => [(string) $this->requireConfig($config, 'domain')],
            'certsan' => array_values(array_filter($this->guardSdk(fn () => $client->listLiveDomains()), fn (string $domain): bool => $this->certificateMatches((string) $certRef['cert'], $domain))),
            default => $this->fail("不支持的域名匹配模式: $pattern"),
        };
        if ($domains === []) {
            $this->fail('未找到证书 SAN 匹配的京东云直播域名');
        }

        foreach ($domains as $domain) {
            $this->guardSdk(fn () => $client->setLiveDomainCertificate($domain, trim($certPem), trim($keyPem)));
        }
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'live' => JdcloudClientFactory::live($credentials),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}
