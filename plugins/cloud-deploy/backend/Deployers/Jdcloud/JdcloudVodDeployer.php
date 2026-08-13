<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 京东云点播 VOD（内联型，直灌 PEM）：先按域名查 domainId，再把证书 PEM + 私钥设到该域名 SSL 配置。
 *
 * 对齐 certimate jdcloud-vod exact 路径：
 *   findDomainIdByDomain（GET /v1/domains 分页，匹配 name → 取 id，int）
 *   → GetHttpSsl（GET /v1/domains/{domainId}:getHttpSsl，取 jumpType）
 *   → SetHttpSsl（POST /v1/domains/{domainId}:setHttpSsl，
 *     body {source=default, title, sslCert, sslKey, jumpType=沿用, enabled=true}）
 *
 * 点播加速域名不支持泛域名，仅 exact（对齐 certimate）；不做 certsan 遍历。
 * usesRemoteCertStore=false → bind 收 {cert,key,chain} 三元组。
 */
class JdcloudVodDeployer extends AbstractDeployer
{
    use MatchesJdcloudCertificateDomains;

    public function provider(): string
    {
        return 'jdcloud';
    }

    public function product(): string
    {
        return 'vod';
    }

    public function label(): string
    {
        return '京东云点播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '点播加速域名', 'type' => 'string', 'required' => false],
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
            $this->fail('京东云点播为内联型，需 PEM 三元组');
        }
        $certPem = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $keyPem = $certRef['key'];

        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        /** @var JdcloudRestClient $client */
        $client = $this->makeClient('vod', $credentials);
        if ($pattern === '' || $pattern === 'exact') {
            $domain = (string) $this->requireConfig($config, 'domain');
            $domainId = $this->guardSdk(fn (): ?int => $client->findVodDomainId($domain));
            $targets = $domainId === null ? [] : [['id' => $domainId, 'name' => $domain]];
        } elseif ($pattern === 'certsan') {
            $targets = array_values(array_filter($this->guardSdk(fn () => $client->listVodDomains()), fn (array $item): bool => $this->certificateMatches((string) $certRef['cert'], $item['name'])));
        } else {
            $this->fail("不支持的域名匹配模式: $pattern");
        }

        if ($targets === []) {
            $this->fail($pattern === 'certsan' ? '未找到证书 SAN 匹配的京东云点播域名' : "未找到点播域名 $domain");
        }

        foreach ($targets as $target) {
            $domainId = (int) $target['id'];
            $this->guardSdk(function () use ($client, $domainId, $certPem, $keyPem) {
                $jumpType = $client->getVodHttpSslJumpType($domainId);
                $title = 'clouddeploy-'.(int) (microtime(true) * 1000);
                $client->setVodHttpSsl($domainId, $title, trim($certPem), trim($keyPem), $jumpType);
            });
        }
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'vod' => JdcloudClientFactory::vod($credentials),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}
