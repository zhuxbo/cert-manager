<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 华为云 DDoS 高防 AAD（内联型，按域名设置证书）。
 *
 * 对齐 certimate deployer huaweicloud-aad：
 *   1. AAD v2 ListInstanceDomains 分页拉实例下域名（GET /v2/aad/instances/{instance_id}/domains?offset=&limit=），
 *      跳过 domain_status="1"（对齐 certimate ignoredStatuses），exact 匹配 domain_name 得 domain_id。
 *   2. AAD v1 SetCertForDomain（POST /v1/aad/domains/set-cert）直灌证书
 *      {op_type:0, domain_id, cert_name, cert_file=完整链, cert_key_file=私钥}。
 *
 * 内联型：AAD 直接给域名设置证书内容（不经证书托管服务），故 usesRemoteCertStore=false、certUploader=null，
 * bind 收 {cert,key,chain} 三元组（对齐 certimate 直传 certPEM/privkeyPEM）。
 *
 * AAD 使用 global 凭证，无 projectId；region 为空时回落 cn-north-4。支持 exact、wildcard、certsan，匹配后逐域名设置证书。
 */
class AadDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    private const PAGE_SIZE = 10;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'aad';
    }

    public function label(): string
    {
        return '华为云 DDoS 高防 AAD';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => false, 'default' => 'cn-north-4'],
            ['key' => 'instance_id', 'label' => 'DDoS 高防实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '网站域名', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array<string,mixed>  $credentials
     * @param  array{region?:string,instance_id:string,domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $instanceId = (string) $this->requireConfig($config, 'instance_id');
        $region = (string) ($config['region'] ?? 'cn-north-4');
        $region = $region !== '' ? $region : 'cn-north-4';
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = (string) ($config['domain'] ?? '');
        if (($pattern === '' || $pattern === 'exact' || $pattern === 'wildcard') && $domain === '') {
            $this->requireConfig($config, 'domain');
        }
        // 内联型：$certRef 为 {cert,key,chain} 三元组。证书本体 + 中间证书拼完整链。
        $certFile = is_array($certRef) ? rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']) : '';
        $certKeyFile = is_array($certRef) ? trim((string) $certRef['key']) : '';
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $region, $instanceId, $pattern, $domain, $certRef, $certFile, $certKeyFile, $certName) {
            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('aad', array_replace($credentials, ['region' => $region]));

            $domainIds = $this->findDomainIds($client, $instanceId, $pattern, $domain, is_array($certRef) ? (string) $certRef['cert'] : '');
            if ($domainIds === []) {
                throw new HuaweicloudApiException('DomainNotFound', '未找到匹配的 AAD 实例域名');
            }

            foreach ($domainIds as $domainId) {
                // SetCertForDomain：op_type=0 表示新增/设置证书。
                $client->post('/v1/aad/domains/set-cert', [
                    'op_type' => 0,
                    'domain_id' => $domainId,
                    'cert_name' => $certName,
                    'cert_file' => $certFile,
                    'cert_key_file' => $certKeyFile,
                ]);
            }
        });
    }

    /**
     * 分页 ListInstanceDomains，跳过 domain_status="1"，按配置模式返回全部匹配 domain_id。
     *
     * @return list<string>
     */
    private function findDomainIds(
        HuaweicloudRestClient $client,
        string $instanceId,
        string $pattern,
        string $domain,
        string $certPem,
    ): array {
        $offset = 0;
        $ids = [];

        while (true) {
            $resp = $client->get("/v2/aad/instances/$instanceId/domains", [
                'offset' => $offset,
                'limit' => self::PAGE_SIZE,
            ]);

            $domains = is_array($resp['domains'] ?? null) ? $resp['domains'] : [];
            foreach ($domains as $item) {
                if (! is_array($item)) {
                    continue;
                }
                // 跳过停用状态（对齐 certimate ignoredStatuses=["1"]）。
                $status = isset($item['domain_status']) ? (string) $item['domain_status'] : '';
                if ($status === '1') {
                    continue;
                }
                $name = is_string($item['domain_name'] ?? null) ? $item['domain_name'] : '';
                $id = isset($item['domain_id']) ? (string) $item['domain_id'] : '';
                $matched = match ($pattern) {
                    '', 'exact' => $name === $domain,
                    'wildcard' => $this->hostnameMatches($domain, $name),
                    'certsan' => $this->certificateMatches($certPem, $name),
                    default => throw new HuaweicloudApiException('UnsupportedDomainMatchPattern', "不支持的域名匹配模式: $pattern"),
                };
                if ($matched && $id !== '') {
                    $ids[] = $id;
                }
            }

            if (count($domains) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        return $ids;
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
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($san)) {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, 'DNS:')) {
                    $names[] = substr($entry, 4);
                }
            }
        }
        if ($names === [] && is_string($parsed['subject']['CN'] ?? null)) {
            $names[] = $parsed['subject']['CN'];
        }

        foreach ($names as $name) {
            if ($this->hostnameMatches($name, $hostname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // AAD 使用 global 凭证、无 projectId；v1/v2 共用 region endpoint。
            'aad' => new HuaweicloudRestClient(
                $this->regionalHost('aad', (string) ($credentials['region'] ?? 'cn-north-4')),
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
