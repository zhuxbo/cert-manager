<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 火山引擎视频点播 VOD（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再设到点播域名。对齐 certimate volcengine-vod（JSON 协议）：
 *   UpdateVodDomainConfig {SpaceName, DomainType, UpdateCdnConfigParam:{Domain, HTTPS:{Switch:true, CertInfo:{CertId}}}}
 *   （Action=UpdateVodDomainConfig, Version=2026-01-01）
 *
 * 仅 exact 域名匹配（不做 wildcard/certsan 遍历）。domain_type 映射：play→vod_play / image→vod_image / third→third。
 * VOD 服务签名 region 固定 cn-north-1（volc-sdk-golang 默认，与 certimate 一致）；证书中心上传走默认 cn-beijing。
 */
class VolcVodDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesVolcDomains;

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'vod';
    }

    public function label(): string
    {
        return '火山引擎视频点播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'space_name', 'label' => '点播空间名称', 'type' => 'string', 'required' => true],
            ['key' => 'domain_type', 'label' => '点播域名类型', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '点播加速域名', 'value' => 'play'],
                ['label' => '图片处理域名', 'value' => 'image'],
                ['label' => '第三方域名', 'value' => 'third'],
            ]],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '点播加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 证书中心默认 cn-beijing（certimate vod 的 certmgr 不传 region）
        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials),
        );
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  证书中心 InstanceId 与可选证书材料
     * @param  array{access_key_id?:string,secret_access_key?:string,project_name?:string}  $credentials
     * @param  array{space_name:string,domain_type:string,domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $spaceName = (string) $this->requireConfig($config, 'space_name');
        $domainType = self::cloudDomainType((string) $this->requireConfig($config, 'domain_type'));
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        [$certId, $certificate] = $this->volcCertificateReference($certRef);
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->fail('缺少配置 domain');
        }

        $this->guardSdk(function () use ($credentials, $spaceName, $domainType, $domain, $certId, $certificate, $pattern) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('vod', $credentials);
            $candidates = in_array($pattern, ['wildcard', 'certsan'], true)
                ? $this->listVodDomains($client, $spaceName, $domainType)
                : [];
            $domains = $this->matchVolcDomains($candidates, $pattern, $domain, $certificate);
            if ($domains === []) {
                $this->fail('未找到匹配的点播域名');
            }
            foreach ($domains as $matchedDomain) {
                $detail = $client->callJson('DescribeVodDomainConfig', '2026-01-01', [
                    'SpaceName' => $spaceName,
                    'DomainType' => $domainType,
                    'DescribeCdnDomainParam' => ['Domain' => $matchedDomain],
                ]);
                $https = is_array($detail['DomainInfo']['DomainConfig']['HTTPS'] ?? null)
                    ? $detail['DomainInfo']['DomainConfig']['HTTPS'] : [];
                if (($https['Switch'] ?? false) === true && (string) ($https['CertInfo']['CertId'] ?? '') === $certId) {
                    continue;
                }
                $client->callJson('UpdateVodDomainConfig', '2026-01-01', [
                    'SpaceName' => $spaceName,
                    'DomainType' => $domainType,
                    'UpdateCdnConfigParam' => [
                        'Domain' => $matchedDomain,
                        'HTTPS' => [
                            'Switch' => true,
                            'CertInfo' => [
                                'CertId' => $certId,
                            ],
                        ],
                    ],
                ]);
            }
        });
    }

    /** @return list<string> */
    private function listVodDomains(VolcRestClient $client, string $spaceName, string $domainType): array
    {
        $domains = [];
        $page = 1;
        do {
            $result = $client->callJson('ListVodDomains', '2026-01-01', [
                'SpaceName' => $spaceName,
                'DomainType' => $domainType,
                'ListCdnDomainsParam' => ['PageNum' => $page, 'PageSize' => 100],
            ]);
            $items = is_array($result['VodInfo']['Domains'] ?? null) ? $result['VodInfo']['Domains'] : [];
            foreach ($items as $item) {
                $candidate = is_array($item) ? (string) ($item['Domain'] ?? '') : '';
                if ($candidate !== '') {
                    $domains[] = $candidate;
                }
            }
            $page++;
        } while (count($items) >= 100);

        return $domains;
    }

    /** domain_type → 火山 DomainType（对齐 certimate convertDomainType2CloudDomainType）。 */
    private static function cloudDomainType(string $type): string
    {
        return match ($type) {
            'play' => 'vod_play',
            'image' => 'vod_image',
            'third' => 'third',
            default => $type,
        };
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // VOD 服务签名 region cn-north-1（volc-sdk-golang 默认）
            'vod' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'vod',
                'cn-north-1',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            // 证书中心默认 cn-beijing
            'certcenter' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'certificate_service',
                'cn-beijing',
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
