<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 华为云 CDN（证书服务型，全局）。
 *
 * 对齐 certimate deployer huaweicloud-cdn 的 exact 路径：
 *   1. 经 HuaweiScmUploader 把证书托管到 SCM 拿 certificate_id（store_kind=huawei_scm，走 RemoteCertStore 去重）。
 *   2. bind 调 CDN UpdateDomainMultiCertificates（PUT /v1.0/cdn/domains/config-https-info）：
 *      body {https:{domain_name, https_switch:1, certificate_type:2(SCM 托管), scm_certificate_id, cert_name}}。
 *
 * CDN 为**全局服务**（cdn.myhuaweicloud.com，global 凭证，无 region/projectId），对齐 certimate createSDKClient 用 global.NewCredentialsBuilder。
 * 企业项目 ID 作 enterprise_project_id 查询参数透传（非空才传，对齐 certimate lo.EmptyableToPtr）。
 *
 * exact 直接绑定；wildcard 经 ListDomains 分页过滤后批量绑定（每请求最多 50 个域名）。
 */
class CdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '华为云 CDN';
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
        $region = (string) ($config['region'] ?? '');

        return new HuaweiScmUploader(
            fn (array $credentials): object => $this->makeClient('scm', array_replace($credentials, ['region' => $region])),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（SCM certificate_id）
     * @param  array<string,mixed>  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        if (! in_array($pattern, ['', 'exact', 'wildcard', 'certsan'], true)) {
            $this->fail("Huawei CDN 不支持的域名匹配模式: $pattern");
        }
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $scmCertId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        $enterpriseProjectId = isset($credentials['enterprise_project_id']) ? (string) $credentials['enterprise_project_id'] : '';

        $this->guardSdk(function () use ($credentials, $pattern, $domain, $certificate, $scmCertId, $certName, $enterpriseProjectId) {
            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            $query = [];
            if ($enterpriseProjectId !== '') {
                $query['enterprise_project_id'] = $enterpriseProjectId;
            }

            $domains = match (true) {
                $pattern === 'certsan' => $this->findMatchingDomains($client, $domain, $pattern, $certificate, $query),
                $pattern === 'wildcard' && str_starts_with($domain, '*.') => $this->findMatchingDomains($client, $domain, $pattern, $certificate, $query),
                default => [$domain],
            };

            foreach (array_chunk($domains, 50) as $chunk) {
                // UpdateDomainMultiCertificates：certificate_type=2 表示使用 SCM 托管证书（scm_certificate_id）。
                $client->put('/v1.0/cdn/domains/config-https-info', [
                    'https' => [
                        'domain_name' => implode(',', $chunk),
                        'https_switch' => 1,
                        'certificate_type' => 2,
                        'scm_certificate_id' => $scmCertId,
                        'cert_name' => $certName,
                    ],
                ], $query);
            }
        });
    }

    /** @return list<string> */
    private function findMatchingDomains(
        HuaweicloudRestClient $client,
        string $domain,
        string $pattern,
        string $certificate,
        array $baseQuery,
    ): array {
        $domains = [];
        for ($page = 1; ; $page++) {
            $response = $client->get('/v1.0/cdn/domains', array_replace($baseQuery, [
                'page_number' => $page,
                'page_size' => 100,
            ]));
            $items = is_array($response['domains'] ?? null) ? $response['domains'] : [];
            foreach ($items as $item) {
                if (! is_array($item)
                    || in_array((string) ($item['domain_status'] ?? ''), ['offline', 'checking', 'check_failed', 'deleting'], true)) {
                    continue;
                }
                $candidate = (string) ($item['domain_name'] ?? '');
                if (($pattern === 'wildcard' && $this->hostnameMatches($domain, $candidate))
                    || ($pattern === 'certsan' && $this->certificateMatchesHostname($certificate, $candidate))) {
                    $domains[] = $candidate;
                }
            }
            if (count($items) < 100) {
                break;
            }
        }

        return $domains;
    }

    private function hostnameMatches(string $pattern, string $hostname): bool
    {
        $suffix = substr(strtolower(rtrim(trim($pattern), '.')), 2);
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if ($suffix === '' || ! str_ends_with($hostname, '.'.$suffix)) {
            return false;
        }
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return $prefix !== '' && ! str_contains($prefix, '.');
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // CDN 全局服务（global 凭证，无 projectId）。
            'cdn' => new HuaweicloudRestClient(
                'cdn.myhuaweicloud.com',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            // SCM 托管走 region 服务（回落 cn-north-4）。
            'scm' => new HuaweicloudRestClient(
                $this->scmHost((string) ($credentials['region'] ?? '')),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
