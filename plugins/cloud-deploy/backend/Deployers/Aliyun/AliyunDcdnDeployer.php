<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Dcdn\V20180115\Dcdn;
use AlibabaCloud\SDK\Dcdn\V20180115\Models\DescribeDcdnUserDomainsRequest;
use AlibabaCloud\SDK\Dcdn\V20180115\Models\SetDcdnDomainSSLCertificateRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 阿里云 DCDN（全站加速，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 dcdn.SetDcdnDomainSSLCertificate 以 CertType=cas + CertId + CertRegion 绑定。
 *
 * exact 直接部署；wildcard 经 DescribeDcdnUserDomains 分页过滤后逐域名绑定。
 */
class AliyunDcdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use BuildsAliyunConfig, MatchesAliyunDomains;
    use MatchesCertificateHostnames;
    use ParsesCasCertIdentifier;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'dcdn';
    }

    public function label(): string
    {
        return '阿里云 DCDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '证书地域', 'type' => 'string', 'required' => false],
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
        // 上传器复用 deployer 的注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials), $this->casRegion($config));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string, mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        if (! in_array($pattern, ['', 'exact', 'wildcard', 'certsan'], true)) {
            $this->fail("Aliyun DCDN 不支持的域名匹配模式: $pattern");
        }
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $remoteCertId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        [$certId, $certRegion] = $this->parseCertIdentifier($remoteCertId);

        $this->guardSdk(function () use ($credentials, $pattern, $domain, $certificate, $certId, $certRegion) {
            /** @var Dcdn $client */
            $client = $this->makeClient('dcdn', $credentials);
            $domains = match ($pattern) {
                '', 'exact' => [$this->normalizeDomain($domain)],
                'wildcard' => str_starts_with($domain, '*.')
                    ? $this->findMatchingDomains($client, $credentials, $domain, $pattern, $certificate)
                    : [$domain],
                default => $this->findMatchingDomains($client, $credentials, $domain, $pattern, $certificate),
            };
            foreach ($domains as $matchedDomain) {
                $client->setDcdnDomainSSLCertificate(new SetDcdnDomainSSLCertificateRequest([
                    'domainName' => $matchedDomain,
                    'certType' => 'cas',
                    'certId' => $certId,
                    'certRegion' => $certRegion,
                    'SSLProtocol' => 'on',
                ]));
            }
        });
    }

    /** @return list<string> */
    private function findMatchingDomains(Dcdn $client, array $credentials, string $domain, string $pattern, string $certificate): array
    {
        $domains = [];
        for ($page = 1; ; $page++) {
            $request = new DescribeDcdnUserDomainsRequest([
                'resourceGroupId' => (string) ($credentials['resource_group_id'] ?? ''),
                'checkDomainShow' => true,
                'pageNumber' => $page,
                'pageSize' => 500,
            ]);
            $response = $client->describeDcdnUserDomains($request);
            $items = is_array($response->body?->domains?->pageData ?? null) ? $response->body->domains->pageData : [];
            $ignored = ['offline', 'checking', 'check_failed', 'stopping', 'deleting'];
            foreach ($items as $item) {
                $candidate = (string) ($item->domainName ?? '');
                if (! in_array((string) ($item->domainStatus ?? ''), $ignored, true)
                    && (($pattern === 'wildcard' && $this->hostnameMatches($domain, $candidate))
                        || ($pattern === 'certsan' && $this->certificateMatchesHostname($certificate, $candidate)))) {
                    $domains[] = $candidate;
                }
            }
            if (count($items) < 500) {
                break;
            }
        }

        return $domains;
    }

    private function normalizeDomain(string $domain): string
    {
        return str_starts_with($domain, '*.') ? substr($domain, 1) : $domain;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            'dcdn' => new Dcdn($this->aliyunConfig($credentials, 'dcdn.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
