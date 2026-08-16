<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 金山云 CDN（内联型）：CDN 支持 ConfigCertificate 直传 PEM（不经证书中心），故 usesRemoteCertStore=false、certUploader=null。
 *
 * 对齐 certimate ksyun-cdn 的 deployToDomain（DEPLOY_TARGET_DOMAIN + exact 匹配）：
 *   1. GET /2019-06-01/GetCdnDomains（Action=GetCdnDomains, Version=2019-06-01）分页拉全部加速域名，
 *      跳过 offline/icp_checking/icp_check_failed/locking/locked 状态。
 *   2. exact 过滤 DomainName == config.domain，得到一个或多个 DomainId。
 *   3. 逐个 POST /2016-09-01/cert/ConfigCertificate（Action=ConfigCertificate, Version=2016-09-01）
 *      {Enable:"on", DomainIds, CertificateName, ServerCertificate=证书, PrivateKey=私钥} 为该域名配置证书。
 *
 * 与 certimate 对齐的取舍：
 * - certimate 支持 domainMatchPattern（exact/wildcard/certsan）与两类 DeployTarget（domain/certificate）。
 *   本端点**仅实现 DEPLOY_TARGET_DOMAIN + exact**核心路径（domain 必填、精确匹配域名后逐个 ConfigCertificate），
 *   不做 wildcard/certsan 的「列举全部域名再泛/SAN 匹配」，也不做 DEPLOY_TARGET_CERTIFICATE（按既有证书 ID 调 SetCertificate）——
 *   与插件其他端点（AliyunDcdn/Qiniu/Baidu cdn 等）「仅 exact」的简化口径一致。如需泛域名按 SAN 批量部署，可逐个域名各配一个 target。
 * - ProjectId 选填（certimate omitempty）：未填则不传，让金山云用默认项目；填则透传过滤。
 */
class KsyunCdnDeployer extends AbstractDeployer
{
    /** 跳过的域名状态（无法配置证书；对齐 certimate getAllDomains 的 ignoredStatuses）。 */
    private const IGNORED_STATUSES = ['offline', 'icp_checking', 'icp_check_failed', 'locking', 'locked'];

    private const PAGE_SIZE = 100;

    public function provider(): string
    {
        return 'ksyun';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '金山云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'select', 'required' => false, 'default' => 'domain', 'options' => [
                ['label' => '加速域名', 'value' => 'domain'],
                ['label' => '已有证书', 'value' => 'certificate'],
            ]],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'select', 'required' => false, 'default' => 'exact', 'options' => [
                ['label' => '精确匹配', 'value' => 'exact'],
                ['label' => '泛域名匹配', 'value' => 'wildcard'],
                ['label' => '证书 SAN 匹配', 'value' => 'certsan'],
            ]],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
            ['key' => 'certificate_id', 'label' => '已有证书 ID', 'type' => 'string', 'required' => false],
            ['key' => 'project_id', 'label' => '项目 ID（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{domain:string,project_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $deployTarget = isset($config['deploy_target']) && $config['deploy_target'] !== ''
            ? (string) $config['deploy_target']
            : 'domain';
        $matchPattern = isset($config['domain_match_pattern']) && $config['domain_match_pattern'] !== ''
            ? (string) $config['domain_match_pattern']
            : 'exact';
        $projectId = isset($config['project_id']) ? (string) $config['project_id'] : '';
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        // 内联型：$certRef 为 {cert,key,chain} 三元组。证书本体 + 中间证书拼完整链。
        $serverCert = is_array($certRef) ? rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']) : '';
        $privateKey = is_array($certRef) ? trim((string) $certRef['key']) : '';

        if ($deployTarget === 'certificate') {
            $certificateId = (string) $this->requireConfig($config, 'certificate_id');
            $this->guardSdk(function () use ($credentials, $certificateId, $certName, $serverCert, $privateKey) {
                /** @var KsyunRestClient $client */
                $client = $this->makeClient('cdn', $credentials);
                $client->post('/2016-09-01/cert/SetCertificate', [
                    'Action' => 'SetCertificate',
                    'Version' => '2016-09-01',
                    'CertificateId' => $certificateId,
                    'CertificateName' => $certName,
                    'ServerCertificate' => $serverCert,
                    'PrivateKey' => $privateKey,
                ]);
            });

            return;
        }
        if ($deployTarget !== 'domain') {
            $this->fail("不支持的部署目标 $deployTarget");
        }
        $domain = $matchPattern === 'certsan' ? '' : (string) $this->requireConfig($config, 'domain');

        $this->guardSdk(function () use ($credentials, $domain, $matchPattern, $projectId, $certName, $serverCert, $privateKey, $certRef) {
            /** @var KsyunRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            $domainIds = $this->findDomainIds($client, $domain, $matchPattern, $projectId, is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '');
            if ($domainIds === []) {
                throw new KsyunApiException('DomainNotFound', "未找到匹配的金山云 CDN 域名: $domain");
            }

            foreach ($domainIds as $domainId) {
                $client->post('/2016-09-01/cert/ConfigCertificate', [
                    'Action' => 'ConfigCertificate',
                    'Version' => '2016-09-01',
                    'Enable' => 'on',
                    'DomainIds' => $domainId,
                    'CertificateName' => $certName,
                    'ServerCertificate' => $serverCert,
                    'PrivateKey' => $privateKey,
                ]);
            }
        });
    }

    /**
     * 分页拉全部域名（跳过无法配置证书的状态），exact 过滤出 DomainName == $domain 的 DomainId 列表。
     *
     * @return list<string>
     */
    private function findDomainIds(KsyunRestClient $client, string $domain, string $matchPattern, string $projectId, string $certPem): array
    {
        $domainIds = [];
        $page = 1;

        while (true) {
            $params = [
                'Action' => 'GetCdnDomains',
                'Version' => '2019-06-01',
                'PageNumber' => (string) $page,
                'PageSize' => (string) self::PAGE_SIZE,
            ];
            if ($projectId !== '') {
                $params['ProjectId'] = $projectId;
            }

            $resp = $client->get('/2019-06-01/GetCdnDomains', $params);
            $domains = is_array($resp['Domains'] ?? null) ? $resp['Domains'] : [];

            foreach ($domains as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $status = is_string($item['DomainStatus'] ?? null) ? $item['DomainStatus'] : '';
                if (in_array($status, self::IGNORED_STATUSES, true)) {
                    continue;
                }
                $name = is_string($item['DomainName'] ?? null) ? $item['DomainName'] : '';
                $id = isset($item['DomainId']) ? (string) $item['DomainId'] : '';
                $matched = match ($matchPattern) {
                    'exact' => $name === $domain,
                    'wildcard' => $this->hostnameMatches($domain, $name),
                    'certsan' => $this->certificateMatches($certPem, $name),
                    default => throw new KsyunApiException('InvalidDomainMatchPattern', "不支持的域名匹配模式: $matchPattern"),
                };
                if ($matched && $id !== '') {
                    $domainIds[] = $id;
                }
            }

            if (count($domains) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $domainIds;
    }

    private function hostnameMatches(string $pattern, string $hostname): bool
    {
        $pattern = strtolower(rtrim(trim($pattern), '.'));
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if (! str_starts_with($pattern, '*.')) {
            return $pattern === $hostname;
        }

        $suffix = substr($pattern, 2);
        if ($suffix === '' || ! str_ends_with($hostname, '.'.$suffix)) {
            return false;
        }

        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return $prefix !== '' && ! str_contains($prefix, '.');
    }

    private function certificateMatches(string $certPem, string $hostname): bool
    {
        $parsed = @openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            return false;
        }

        $names = [];
        $subjectAltName = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($subjectAltName)) {
            foreach (explode(',', $subjectAltName) as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, 'DNS:')) {
                    $names[] = substr($entry, 4);
                }
            }
        }
        if ($names === []) {
            $commonName = $parsed['subject']['CN'] ?? '';
            if (is_string($commonName) && $commonName !== '') {
                $names[] = $commonName;
            }
        }

        foreach ($names as $name) {
            if ($this->hostnameMatches($name, $hostname)) {
                return true;
            }
        }

        return false;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new KsyunRestClient(
                'cdn',
                'cdn.api.ksyun.com',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return KsyunErrorSanitizer::sanitize($e);
    }
}
