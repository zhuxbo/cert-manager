<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Vod\V20170321\Models\DescribeVodUserDomainsRequest;
use AlibabaCloud\SDK\Vod\V20170321\Models\SetVodDomainSSLCertificateRequest;
use AlibabaCloud\SDK\Vod\V20170321\Vod;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 阿里云 VOD（视频点播，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 vod.SetVodDomainSSLCertificate 以 CertType=cas + CertId + CertName + CertRegion 绑定。
 *
 * 与 dcdn 的差异：vod 绑定**额外需要 CertName**。因 RemoteCertStore 命中时会跳过上传（直接复用既有
 * remote_cert_id），上传时的 CertName 不可得，故 bind 内**按 CertId 反查 CAS GetUserCertificateDetail
 * 拿 name**（与上传命中/未命中无关，始终能取到），避免把 CertName 塞进 remote_cert_id 污染 dcdn 共用的去重值。
 *
 * exact 直接部署；wildcard 经 DescribeVodUserDomains 分页匹配在线域名后逐域名绑定。
 */
class AliyunVodDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
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
        return 'vod';
    }

    public function label(): string
    {
        return '阿里云视频点播';
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
            $this->fail("Aliyun VOD 不支持的域名匹配模式: $pattern");
        }
        $region = (string) ($config['region'] ?? '');
        $credentials = array_replace($credentials, ['region' => $region]);
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $remoteCertId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        [$certId, $certRegion] = $this->parseCertIdentifier($remoteCertId);

        $this->guardSdk(function () use ($credentials, $pattern, $domain, $certificate, $certId, $certRegion) {
            // 反查 CertName（vod 绑定必填；RemoteCertStore 命中时上传被跳过，只能按 certId 取）
            /** @var Cas $cas */
            $cas = $this->makeClient('cas', $credentials);
            $detail = $cas->getUserCertificateDetail(new GetUserCertificateDetailRequest([
                'certId' => $certId,
                'certFilter' => true,
            ]));
            $certName = $detail->body?->name ?? '';

            /** @var Vod $client */
            $client = $this->makeClient('vod', $credentials);
            $domains = match ($pattern) {
                '', 'exact' => [$domain],
                'wildcard' => str_starts_with($domain, '*.') ? $this->findMatchingDomains($client, $domain, $pattern, $certificate) : [$domain],
                default => $this->findMatchingDomains($client, $domain, $pattern, $certificate),
            };
            foreach ($domains as $matchedDomain) {
                $client->setVodDomainSSLCertificate(new SetVodDomainSSLCertificateRequest([
                    'domainName' => $matchedDomain,
                    'certType' => 'cas',
                    'certId' => $certId,
                    'certName' => $certName,
                    'certRegion' => $certRegion,
                    'SSLProtocol' => 'on',
                ]));
            }
        });
    }

    /** @return list<string> */
    private function findMatchingDomains(Vod $client, string $domain, string $pattern, string $certificate): array
    {
        $domains = [];
        for ($page = 1; ; $page++) {
            $response = $client->describeVodUserDomains(new DescribeVodUserDomainsRequest([
                'domainStatus' => 'online',
                'pageNumber' => $page,
                'pageSize' => 50,
            ]));
            $items = is_array($response->body?->domains?->pageData ?? null) ? $response->body->domains->pageData : [];
            foreach ($items as $item) {
                $candidate = (string) ($item->domainName ?? '');
                if (($pattern === 'wildcard' && $this->hostnameMatches($domain, $candidate))
                    || ($pattern === 'certsan' && $this->certificateMatchesHostname($certificate, $candidate))) {
                    $domains[] = $candidate;
                }
            }
            if (count($items) < 50) {
                break;
            }
        }

        return $domains;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            'vod' => new Vod($this->aliyunConfig(
                $credentials,
                ($credentials['region'] ?? '') !== ''
                    ? 'vod.'.(string) $credentials['region'].'.aliyuncs.com'
                    : 'vod.cn-hangzhou.aliyuncs.com',
            )),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
