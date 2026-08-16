<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cdn\V20180510\Cdn;
use AlibabaCloud\SDK\Cdn\V20180510\Models\DescribeUserDomainsRequest;
use AlibabaCloud\SDK\Cdn\V20180510\Models\SetCdnDomainSSLCertificateRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 阿里云 CDN（内联型）：CDN 支持 CertType=upload 直传 PEM，不走证书服务，故 usesRemoteCertStore=false、certUploader=null。
 * 绑定调官方 SDK Cdn::setCdnDomainSSLCertificate。
 */
class AliyunCdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use BuildsAliyunConfig, MatchesAliyunDomains, ParsesCasCertIdentifier;
    use MatchesCertificateHostnames;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '阿里云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => false],
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
        $region = (string) ($config['region'] ?? '');

        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials), $region);
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string, mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $remoteCertId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        [$certId, $certRegion] = $this->parseCertIdentifier($remoteCertId);

        $this->guardSdk(function () use ($credentials, $domain, $pattern, $certificate, $certId, $certRegion) {
            /** @var Cdn $client */
            $client = $this->makeClient('cdn', $credentials);
            $domains = $pattern === 'exact' || ($pattern === 'wildcard' && ! str_starts_with($domain, '*.'))
                ? [str_starts_with($domain, '*.') ? substr($domain, 1) : $domain]
                : $this->matchingDomains($client, $domain, $pattern, $certificate, (string) ($credentials['resource_group_id'] ?? ''));

            foreach ($domains as $matchedDomain) {
                $client->setCdnDomainSSLCertificate(new SetCdnDomainSSLCertificateRequest([
                    'domainName' => $matchedDomain,
                    'SSLProtocol' => 'on',
                    'certType' => 'cas',
                    'certId' => $certId,
                    'certRegion' => $certRegion,
                ]));
            }
        });
    }

    /** @return list<string> */
    private function matchingDomains(Cdn $client, string $domain, string $pattern, string $certificate, string $resourceGroupId): array
    {
        if (! in_array($pattern, ['wildcard', 'certsan'], true)) {
            $this->fail("Aliyun CDN 不支持的域名匹配模式: $pattern");
        }

        $matched = [];
        for ($page = 1; ; $page++) {
            $request = ['pageNumber' => $page, 'pageSize' => 500];
            if ($resourceGroupId !== '') {
                $request['resourceGroupId'] = $resourceGroupId;
            }
            $items = $client->describeUserDomains(new DescribeUserDomainsRequest($request))->body?->domains?->pageData ?? [];
            foreach ($items as $item) {
                if (in_array((string) $item->domainStatus, ['offline', 'checking', 'check_failed', 'stopping', 'deleting'], true)) {
                    continue;
                }
                $hostname = (string) $item->domainName;
                if (($pattern === 'wildcard' && $this->hostnameMatches($domain, $hostname))
                    || ($pattern === 'certsan' && $this->certificateMatchesHostname($certificate, $hostname))) {
                    $matched[] = $hostname;
                }
            }
            if (count($items) < 500) {
                break;
            }
        }
        if ($matched === []) {
            $this->fail('未找到匹配的 CDN 域名');
        }

        return $matched;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $region = (string) ($credentials['region'] ?? '');
        $casEndpoint = $region === '' || $region === 'cn-hangzhou' ? 'cas.aliyuncs.com' : "cas.$region.aliyuncs.com";

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $casEndpoint)),
            'cdn' => new Cdn($this->aliyunConfig($credentials, 'cdn.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
