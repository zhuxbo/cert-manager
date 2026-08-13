<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
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
class JdcloudCdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;

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
        return new JdcloudSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  云端 certId 与可选证书材料
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        $certId = is_array($certRef) ? $certRef['remote_cert_id'] : $certRef;
        $certificate = is_array($certRef) ? $certRef['cert'] : '';
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $this->guardSdk(function () use ($credentials, $domain, $certId, $certificate, $pattern) {
            /** @var JdcloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $candidates = in_array($pattern, ['wildcard', 'certsan'], true) ? $client->listCdnDomains() : [];
            $domains = match ($pattern) {
                '', 'exact' => [$domain],
                'wildcard' => array_values(array_filter(
                    $candidates,
                    fn (string $candidate): bool => $this->certificateHostnamePatternMatches($domain, $candidate),
                )),
                'certsan' => $certificate === '' ? $this->fail('certsan 匹配缺少证书材料') : array_values(array_filter(
                    $candidates,
                    fn (string $candidate): bool => $this->certificateMatchesHostname($certificate, $candidate),
                )),
                default => $this->fail("不支持的域名匹配模式 $pattern"),
            };
            if ($domains === []) {
                $this->fail('未找到匹配的 CDN 域名');
            }
            foreach ($domains as $matchedDomain) {
                $jumpType = $client->queryCdnDomainHttpsJumpType($matchedDomain);
                $client->setCdnHttpType($matchedDomain, $certId, $jumpType);
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'ssl' => JdcloudClientFactory::ssl($credentials),
            'cdn' => JdcloudClientFactory::cdn($credentials),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}
