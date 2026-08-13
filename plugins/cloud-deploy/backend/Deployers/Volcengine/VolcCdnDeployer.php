<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 火山引擎 CDN（证书服务型）：证书经火山 CDN 自有证书空间上传拿 CertId（走 RemoteCertStore 去重），
 * 再把 CertId 关联到加速域名。对齐 certimate volcengine-cdn：
 *   BatchDeployCert {Domain, CertId}（Action=BatchDeployCert, Version=2021-03-01）
 *
 * 仅实现 exact domain 核心路径（对齐插件既有约定）；不做 certimate 的 wildcard/certsan 遍历。
 * CDN 为 region-less，签名 region 固定 cn-north-1（与 certimate 一致），故凭证里的 region 不参与。
 */
class VolcCdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesVolcDomains;

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
        // CDN 走自有证书空间（VolcCdnUploader），非证书中心。
        return new VolcCdnUploader(fn (array $credentials): object => $this->makeClient('cdn', $credentials));
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  CDN CertId 与可选证书材料
     * @param  array{access_key_id?:string,secret_access_key?:string,project_name?:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        [$certId] = $this->volcCertificateReference($certRef);
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $this->guardSdk(function () use ($credentials, $domain, $certId, $pattern) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            $domains = match ($pattern) {
                '', 'exact' => [$domain],
                'wildcard' => $this->cdnWildcardDomains($client, $credentials, $domain),
                'certsan' => $this->cdnCertificateDomains($client, $certId),
                default => $this->fail("不支持的域名匹配模式 $pattern"),
            };
            foreach ($domains as $matchedDomain) {
                $client->callJson('BatchDeployCert', '2021-03-01', [
                    'Domain' => $matchedDomain,
                    'CertId' => $certId,
                ]);
            }
        });
    }

    /** @return list<string> */
    private function cdnWildcardDomains(VolcRestClient $client, array $credentials, string $domain): array
    {
        if ($domain === '') {
            $this->fail('缺少配置 domain');
        }
        if (! str_starts_with($domain, '*.')) {
            return [$domain];
        }
        $domains = [];
        $page = 1;
        do {
            $result = $client->callJson('ListCdnDomains', '2021-03-01', [
                'Project' => (string) ($credentials['project_name'] ?? ''),
                'Domain' => substr($domain, 2),
                'Status' => 'online',
                'PageNum' => $page,
                'PageSize' => 100,
            ]);
            $items = is_array($result['Data'] ?? null) ? $result['Data'] : [];
            foreach ($items as $item) {
                $candidate = is_array($item) ? (string) ($item['Domain'] ?? '') : '';
                if ($candidate !== '' && $this->certificateHostnamePatternMatches($domain, $candidate)) {
                    $domains[] = $candidate;
                }
            }
            $page++;
        } while (count($items) >= 100);
        if ($domains === []) {
            $this->fail('未找到匹配泛域名的 CDN 域名');
        }

        return $domains;
    }

    /** @return list<string> */
    private function cdnCertificateDomains(VolcRestClient $client, string $certId): array
    {
        $result = $client->callJson('DescribeCertConfig', '2021-03-01', ['CertId' => $certId]);
        $domains = [];
        foreach (['CertNotConfig', 'OtherCertConfig'] as $key) {
            foreach (is_array($result[$key] ?? null) ? $result[$key] : [] as $item) {
                $domain = is_array($item) ? (string) ($item['Domain'] ?? '') : '';
                if ($domain !== '') {
                    $domains[] = $domain;
                }
            }
        }
        if ($domains === [] && ! is_array($result['SpecifiedCertConfig'] ?? null)) {
            $this->fail('未找到证书可关联的 CDN 域名');
        }

        return $domains;
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
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}
