<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 百度智能云 CDN（内联型）：CDN 支持 PutCert 直传 PEM（不经证书中心），故 usesRemoteCertStore=false、certUploader=null。
 *
 * 对齐 certimate baiducloud-cdn 的 updateDomainCertificate：调 BCE CDN REST
 *   PUT /v2/{domain}/certificates
 *   {certificate:{certName, certServerData, certPrivateData, certLinkData}, httpsEnable:"ON"}
 *
 * exact 直接更新；wildcard/certsan 先通过 GET /v2/domain 按 marker 分页列举，
 * 再按单层 TLS 泛域名或内联叶证书 SAN/CN 过滤后批量更新。
 */
class BaiduCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'baidu';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '百度智能云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{domain_match_pattern?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = (string) ($config['domain'] ?? '');
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $domain === '') {
            $this->requireConfig($config, 'domain');
        }
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        /** @var BaiduRestClient $client */
        $client = $this->makeClient('cdn', $credentials);
        $domains = match ($pattern) {
            '', 'exact' => [$domain],
            'wildcard' => str_starts_with($domain, '*.')
                ? array_values(array_filter($this->listDomains($client), fn (string $name): bool => $this->hostnameMatches($domain, $name)))
                : [$domain],
            'certsan' => array_values(array_filter($this->listDomains($client), fn (string $name): bool => $this->certificateMatches((string) $certRef['cert'], $name))),
            default => $this->fail("不支持的域名匹配模式: $pattern"),
        };
        if ($domains === []) {
            $this->fail('未找到匹配的百度云 CDN 域名');
        }

        foreach ($domains as $matchedDomain) {
            $this->guardSdk(fn () => $client->request('PUT', "/v2/$matchedDomain/certificates", [
                'certificate' => ['certName' => $certName, 'certServerData' => trim($certRef['cert']), 'certPrivateData' => trim($certRef['key']), 'certLinkData' => trim($certRef['chain'])],
                'httpsEnable' => 'ON',
            ], []));
        }
    }

    /** @return list<string> */
    private function listDomains(BaiduRestClient $client): array
    {
        $marker = '';
        $domains = [];
        do {
            $response = $this->guardSdk(fn () => $client->request('GET', '/v2/domain', null, $marker === '' ? [] : ['marker' => $marker]));
            foreach (($response->domains ?? []) as $item) {
                $name = is_string($item) ? $item : (string) ($item->name ?? '');
                if ($name !== '') {
                    $domains[] = $name;
                }
            }
            $marker = (string) ($response->nextMarker ?? '');
        } while ($marker !== '');

        return array_values(array_unique($domains));
    }

    private function certificateMatches(string $pem, string $domain): bool
    {
        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed)) {
            return false;
        }
        preg_match_all('/DNS:([^,\s]+)/', (string) ($parsed['extensions']['subjectAltName'] ?? ''), $matches);
        $names = $matches[1];
        if ($names === [] && is_string($parsed['subject']['CN'] ?? null)) {
            $names[] = $parsed['subject']['CN'];
        }

        foreach ($names as $name) {
            if ((str_starts_with($name, '*.') && $this->hostnameMatches($name, $domain)) || strcasecmp($name, $domain) === 0) {
                return true;
            }
        }

        return false;
    }

    private function hostnameMatches(string $wildcard, string $domain): bool
    {
        $suffix = substr(strtolower($wildcard), 2);
        $domain = strtolower($domain);

        return str_ends_with($domain, '.'.$suffix) && ! str_contains(substr($domain, 0, -strlen('.'.$suffix)), '.');
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // CDN 为 region-less endpoint，路径前缀 /v2。
            'cdn' => new BaiduRestClient('cdn.baidubce.com', $credentials),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BaiduErrorSanitizer::sanitize($e);
    }
}
