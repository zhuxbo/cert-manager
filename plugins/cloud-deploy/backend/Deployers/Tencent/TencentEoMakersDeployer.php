<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateRequest;
use TencentCloud\Teo\V20220901\TeoClient;
use Throwable;

/** 腾讯云 EdgeOne Pages Makers 自定义域名证书部署。 */
class TencentEoMakersDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;
    use UsesTencentEndpoint;

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'eo-makers';
    }

    public function label(): string
    {
        return '腾讯云 EdgeOne Makers';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'project_id', 'label' => 'Makers 项目 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domains', 'label' => '加速域名列表', 'type' => 'string', 'required' => false],
            ['key' => 'enable_multiple_ssl', 'label' => '同域名多证书', 'type' => 'boolean', 'required' => false, 'default' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient(
            'ssl',
            $this->withTencentEndpoint($credentials, $config),
        ));
    }

    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $projectId = (string) $this->requireConfig($config, 'project_id');
        $apiToken = trim((string) ($credentials['api_token'] ?? ''));
        if ($apiToken === '') {
            $this->fail('缺少凭证 api_token');
        }
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $configuredDomains = $this->normalizeDomains($config['domains'] ?? '');
        if (in_array($pattern, ['', 'exact', 'wildcard'], true) && $configuredDomains === []) {
            $this->fail('缺少配置 domains');
        }
        if (! in_array($pattern, ['', 'exact', 'wildcard', 'certsan'], true)) {
            $this->fail("不支持的域名匹配模式: $pattern");
        }

        $credentials = $this->withTencentEndpoint($credentials, $config);
        $credentials['api_token'] = $apiToken;
        $certId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : $certRef;
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $multiple = $this->truthy($config['enable_multiple_ssl'] ?? false);

        $domainsInProject = $this->guardSdk(function () use ($credentials, $projectId): array {
            /** @var TencentEoMakersClient $makers */
            $makers = $this->makeClient('makers', $credentials);

            return $makers->listCustomDomains($projectId);
        });
        if ($domainsInProject === []) {
            $this->fail("Makers 项目 $projectId 下未找到自定义域名");
        }
        $zoneId = $domainsInProject[0]['zone_id'];
        $candidates = array_column($domainsInProject, 'domain');
        $domains = match ($pattern) {
            '', 'exact' => $configuredDomains,
            'wildcard' => array_values(array_filter($candidates, function (string $domain) use ($configuredDomains): bool {
                foreach ($configuredDomains as $configured) {
                    if ($this->certificateHostnamePatternMatches($configured, $domain)) {
                        return true;
                    }
                }

                return false;
            })),
            'certsan' => array_values(array_filter(
                $candidates,
                fn (string $domain): bool => $this->certificateMatchesHostname($certificate, $domain),
            )),
        };
        if ($domains === []) {
            $this->fail('未找到匹配的 EdgeOne Makers 自定义域名');
        }

        /** @var TeoClient $teo */
        $teo = $this->guardSdk(fn (): object => $this->makeClient('teo', $credentials));
        $response = $this->guardSdk(fn (): mixed => $teo->callJson(
            'DescribeHostCertificates',
            json_encode(['ZoneId' => $zoneId], JSON_THROW_ON_ERROR),
        ));
        $certificates = $this->normalizeHostCertificates(is_array($response) ? $response : []);
        $domains = array_values(array_filter(
            $domains,
            fn (string $domain): bool => ! $this->domainHasCertificate($certificates[$domain] ?? [], $certId),
        ));
        if ($domains === []) {
            return;
        }

        $algorithm = $multiple ? $this->certificateAlgorithm($certificate) : '';
        $batches = $multiple ? array_map(fn (string $domain): array => [$domain], $domains) : [$domains];
        foreach ($batches as $hosts) {
            $serverCertInfo = [['CertId' => $certId]];
            if ($multiple) {
                foreach ($certificates[$hosts[0]] ?? [] as $existing) {
                    $signAlgorithm = strtoupper(strtok($existing['sign_algo'], ' ') ?: '');
                    $expiresAt = strtotime($existing['expire_time']);
                    if ($existing['id'] === $certId || $signAlgorithm === $algorithm || $expiresAt === false || $expiresAt <= time()) {
                        continue;
                    }
                    $serverCertInfo[] = ['CertId' => $existing['id']];
                }
            }
            $request = new ModifyHostsCertificateRequest;
            $request->deserialize([
                'ZoneId' => $zoneId,
                'Mode' => 'sslcert',
                'Hosts' => $hosts,
                'ServerCertInfo' => $serverCertInfo,
            ]);
            $this->guardSdk(fn (): mixed => $teo->ModifyHostsCertificate($request));
        }
    }

    /** @return list<string> */
    private function normalizeDomains(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : (preg_split('/[\r\n,，;；]+/u', (string) $raw) ?: []);

        return array_values(array_unique(array_filter(array_map(fn ($item): string => trim((string) $item), $items))));
    }

    /** @return array<string,list<array{id:string,sign_algo:string,expire_time:string}>> */
    private function normalizeHostCertificates(array $response): array
    {
        $out = [];
        foreach (is_array($response['HostCertificates'] ?? null) ? $response['HostCertificates'] : [] as $host) {
            if (! is_array($host) || ! is_string($host['Host'] ?? null) || $host['Host'] === '') {
                continue;
            }
            $out[$host['Host']] = [];
            foreach (is_array($host['HostCertInfo'] ?? null) ? $host['HostCertInfo'] : [] as $certificate) {
                if (! is_array($certificate) || ! is_string($certificate['CertId'] ?? null) || $certificate['CertId'] === '') {
                    continue;
                }
                $out[$host['Host']][] = [
                    'id' => $certificate['CertId'],
                    'sign_algo' => is_string($certificate['SignAlgo'] ?? null) ? $certificate['SignAlgo'] : '',
                    'expire_time' => is_string($certificate['ExpireTime'] ?? null) ? $certificate['ExpireTime'] : '',
                ];
            }
        }

        return $out;
    }

    private function domainHasCertificate(array $certificates, string $certId): bool
    {
        foreach ($certificates as $certificate) {
            if ($certificate['id'] === $certId) {
                return true;
            }
        }

        return false;
    }

    private function certificateAlgorithm(string $certificate): string
    {
        $publicKey = $certificate !== '' ? @openssl_pkey_get_public($certificate) : false;
        $details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
        $type = is_array($details) ? ($details['type'] ?? null) : null;

        return match ($type) {
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_EC => 'ECC',
            default => $this->fail('无法识别 EdgeOne 多证书的证书算法'),
        };
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        if ($kind === 'makers') {
            $endpoint = (string) ($credentials['endpoint'] ?? '');
            $host = str_contains($endpoint, 'intl.tencentcloudapi.com')
                ? 'pages-api.edgeone.ai'
                : 'pages-api.cloud.tencent.com';

            return new TencentEoMakersClient($this->outboundHttpClient("https://$host/v1/", [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.(string) ($credentials['api_token'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ]));
        }

        $credential = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            'ssl' => new SslClient($credential, '', $profile),
            'teo' => new TeoClient($credential, '', $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
