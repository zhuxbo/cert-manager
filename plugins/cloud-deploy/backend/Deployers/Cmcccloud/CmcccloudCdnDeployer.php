<?php

namespace Plugins\CloudDeploy\Deployers\Cmcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 移动云 CDN（内联型）：移动云 CDN 直接给域名添加证书（AddDomainServerCertificate 直传 PEM），
 * 不经证书中心，故 usesRemoteCertStore=false、certUploader=null。
 *
 * 对齐 certimate cmcccloud-cdn 的 Deploy（exact / wildcard / certsan 匹配）：
 *   1. DescribeUserDomains 分页拉全部加速域名（page/pageSize=10），跳过审核中/下线等状态、跳过已删除。
 *   2. exact 过滤 domainName == config.domain 得 domainId 列表（int）。
 *   3. 逐个 AddDomainServerCertificate {domainId(int), crtName, certificate, privateKey} 添加证书。
 *
 * 资源池：CDN 固定 CIDC-CORE-00（对齐 cmcdn SDK NewClient 写死 PoolId=CIDC-CORE-00 → endpoint ecloud.10086.cn）。
 *
 * eCloud 统一响应体把数据放 body 字段（{state, errorCode, errorMessage, body}）。
 */
class CmcccloudCdnDeployer extends AbstractDeployer
{
    /** CDN 固定资源池（对齐 cmcdn SDK）。 */
    private const POOL_ID = 'CIDC-CORE-00';

    /** 跳过的域名状态（无法配置证书；对齐 certimate getAllDomains 的 ignoredStatuses）。 */
    private const IGNORED_STATUSES = [
        'ADD_AUDITING', 'ADD_OPENING', 'PAUSE_AUDITING', 'PAUSE_HANDLING',
        'OFFLINE_AUDITING', 'OFFLINE_HANDLING', 'OFFLINE', 'AUDIT_FAIL', 'ADD_FAIL', 'OFFLINE_FAIL',
    ];

    private const PAGE_SIZE = 10;

    private const GW_DESCRIBE_DOMAINS = '/api/openapi-ecdn/domainManager/openapi/domain/describeUserDomains';

    private const GW_ADD_CERTIFICATE = '/api/openapi-ecdn/domainManager/openapi/certificate/addDomainServerCertificate';

    public function provider(): string
    {
        return 'cmcccloud';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '移动云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配（exact/wildcard/certsan）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $matchPattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = $matchPattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        $crtName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        // 内联型：$certRef 为 {cert,key,chain} 三元组。证书本体 + 中间证书拼完整链。
        $certificate = is_array($certRef) ? rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']) : '';
        $serverCertificate = is_array($certRef) ? (string) $certRef['cert'] : '';
        $privateKey = is_array($certRef) ? trim((string) $certRef['key']) : '';

        $this->guardSdk(function () use ($credentials, $domain, $matchPattern, $crtName, $certificate, $serverCertificate, $privateKey) {
            /** @var CmcccloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            $domainIds = $this->findDomainIds($client, $domain, $matchPattern, $serverCertificate);
            if ($domainIds === []) {
                throw new CmcccloudApiException('DomainNotFound', "未找到匹配的移动云 CDN 域名: $domain");
            }

            foreach ($domainIds as $domainId) {
                $client->call('POST', self::GW_ADD_CERTIFICATE, [], [], [
                    'domainId' => $domainId,
                    'crtName' => $crtName,
                    'certificate' => $certificate,
                    'privateKey' => $privateKey,
                ]);
            }
        });
    }

    /**
     * 分页拉全部域名（跳过无法配置证书的状态 + 已删除），exact 过滤出 domainName == $domain 的 domainId 列表。
     *
     * @return list<int>
     */
    private function findDomainIds(CmcccloudRestClient $client, string $domain, string $matchPattern, string $serverCertificate): array
    {
        $domainIds = [];
        $page = 1;

        while (true) {
            $resp = $client->call('GET', self::GW_DESCRIBE_DOMAINS, [], [
                'page' => (string) $page,
                'pageSize' => (string) self::PAGE_SIZE,
            ]);
            $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
            $list = is_array($body['list'] ?? null) ? $body['list'] : [];

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (($item['deleted'] ?? false) === true) {
                    continue;
                }
                $status = is_string($item['domainStatus'] ?? null) ? $item['domainStatus'] : '';
                if (in_array($status, self::IGNORED_STATUSES, true)) {
                    continue;
                }
                $name = is_string($item['domainName'] ?? null) ? $item['domainName'] : '';
                $id = $item['domainId'] ?? null;
                $matched = match ($matchPattern) {
                    '', 'exact' => $name === $domain,
                    'wildcard' => $this->matchesWildcard($domain, $name),
                    'certsan' => $this->matchesCertificate($serverCertificate, $name),
                    default => throw new CmcccloudApiException('InvalidMatchPattern', "不支持的域名匹配模式: $matchPattern"),
                };
                if ($matched && (is_int($id) || (is_string($id) && $id !== ''))) {
                    $domainIds[] = (int) $id;
                }
            }

            if (count($list) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $domainIds;
    }

    private function matchesWildcard(string $pattern, string $hostname): bool
    {
        if (strcasecmp($pattern, $hostname) === 0) {
            return true;
        }
        if (str_starts_with($hostname, '.') || str_starts_with($hostname, '*.')) {
            return strcasecmp(ltrim($pattern, '*'), ltrim($hostname, '*')) === 0;
        }
        if (! str_starts_with($pattern, '*.')) {
            return false;
        }
        $suffix = substr($pattern, 2);
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return str_ends_with(strtolower($hostname), '.'.strtolower($suffix)) && $prefix !== '' && ! str_contains($prefix, '.');
    }

    private function matchesCertificate(string $certificate, string $hostname): bool
    {
        $parsed = @openssl_x509_parse($certificate);
        if (! is_array($parsed)) {
            return false;
        }
        $names = [];
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);
                if (str_starts_with(strtoupper($entry), 'DNS:')) {
                    $names[] = trim(substr($entry, 4));
                }
            }
        }
        if ($names === []) {
            $cn = $parsed['subject']['CN'] ?? '';
            if (is_string($cn) && $cn !== '') {
                $names[] = $cn;
            }
        }

        foreach ($names as $name) {
            if ($this->matchesWildcard($name, $hostname)) {
                return true;
            }
        }

        return false;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new CmcccloudRestClient(
                $credentials['access_key_id'] ?? '',
                $credentials['access_key_secret'] ?? '',
                self::POOL_ID,
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CmcccloudErrorSanitizer::sanitize($e);
    }
}
