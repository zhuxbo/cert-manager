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
use TencentCloud\Teo\V20220901\Models\DescribeAccelerationDomainsRequest;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateRequest;
use TencentCloud\Teo\V20220901\TeoClient;
use Throwable;

/**
 * 腾讯云 EdgeOne（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 TEO（teo/v20220901）ModifyHostsCertificate 以 Mode='sslcert' +
 * ServerCertInfo[].CertId 把证书绑到站点（ZoneId）下的加速域名（Hosts）。
 * 仅实现 certimate tencentcloud-eo 的 exact 单域名核心路径（不做 wildcard/certsan/多证书 EnableMultipleSSL）。
 */
class TencentEoDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;
    use MatchesTencentCertificateDomains;
    use UsesTencentEndpoint;

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'eo';
    }

    public function label(): string
    {
        return '腾讯云 EdgeOne';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'zone_id', 'label' => '站点 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domains', 'label' => '加速域名列表', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => '加速域名（兼容旧配置）', 'type' => 'string', 'required' => false],
            ['key' => 'enable_multiple_ssl', 'label' => '同域名多证书', 'type' => 'boolean', 'required' => false, 'default' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $this->withTencentEndpoint($credentials, $config)));
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  remote_cert_id 与可选证书材料
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{zone_id:string,domain_match_pattern?:string,domains?:list<string>|string,domain?:string,enable_multiple_ssl?:bool}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $zoneId = $this->requireConfig($config, 'zone_id');
        $certId = is_array($certRef) ? $certRef['remote_cert_id'] : $certRef;
        $certificate = is_array($certRef) ? $certRef['cert'] : '';
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $configured = $this->normalizeEoDomains($config['domains'] ?? ($config['domain'] ?? ''));
        if (($pattern === '' || $pattern === 'exact' || $pattern === 'wildcard') && $configured === []) {
            $this->fail('缺少配置 domain');
        }
        /** @var TeoClient $client */
        $client = $this->makeClient('teo', $credentials);
        $all = $this->listEoDomains($client, (string) $zoneId);
        if ($all === []) {
            $this->fail("站点 $zoneId 下未找到加速域名");
        }
        $domains = match ($pattern) {
            '', 'exact' => $configured,
            'wildcard' => array_values(array_filter(array_keys($all), function (string $domain) use ($configured): bool {
                foreach ($configured as $name) {
                    if (str_starts_with($name, '*.') ? $this->tencentHostnameMatches($name, $domain) : strcasecmp($name, $domain) === 0) {
                        return true;
                    }
                }

                return false;
            })),
            'certsan' => $certificate !== ''
                ? array_values(array_filter(array_keys($all), fn (string $domain): bool => $this->certificateMatchesHostname($certificate, $domain)))
                : $this->matchTencentCertificateDomains($certId, $credentials, array_keys($all)),
            default => $this->fail("不支持的域名匹配模式: $pattern"),
        };
        $domains = array_values(array_filter($domains, fn (string $domain): bool => ! $this->eoDomainHasCertificate($all[$domain] ?? [], $certId)));
        if ($domains === []) {
            return;
        }

        $multiple = (bool) ($config['enable_multiple_ssl'] ?? false);
        $algorithm = $multiple ? $this->eoCertificateAlgorithm($certificate) : '';
        $this->guardSdk(function () use ($client, $zoneId, $domains, $certId, $all, $multiple, $algorithm) {
            $batches = $multiple ? array_map(fn (string $domain): array => [$domain], $domains) : [$domains];
            foreach ($batches as $hosts) {
                $serverCertInfo = [['CertId' => $certId]];
                if ($multiple) {
                    foreach ($all[$hosts[0]] ?? [] as $existing) {
                        $signAlgorithm = strtoupper(strtok($existing['sign_algo'], ' ') ?: '');
                        $expiresAt = strtotime($existing['expire_time']);
                        if ($signAlgorithm === $algorithm || $expiresAt === false || $expiresAt <= time()) {
                            continue;
                        }
                        $serverCertInfo[] = ['CertId' => (string) $existing['id']];
                    }
                }
                $req = new ModifyHostsCertificateRequest;
                $req->deserialize([
                    'ZoneId' => $zoneId,
                    'Mode' => 'sslcert',
                    'Hosts' => $hosts,
                    'ServerCertInfo' => $serverCertInfo,
                ]);
                $client->ModifyHostsCertificate($req);
            }
        });
    }

    /** @return array<string,list<array{id:string,sign_algo:string,expire_time:string}>> */
    private function listEoDomains(object $client, string $zoneId): array
    {
        $offset = 0;
        $out = [];
        do {
            $response = $this->guardSdk(function () use ($client, $zoneId, $offset) {
                $request = new DescribeAccelerationDomainsRequest;
                $request->deserialize(['ZoneId' => $zoneId, 'Offset' => $offset, 'Limit' => 100]);

                return $client->DescribeAccelerationDomains($request);
            });
            $items = $response->getAccelerationDomains() ?? [];
            foreach ($items as $item) {
                $name = (string) $item->getDomainName();
                if ($name === '') {
                    continue;
                }
                $out[$name] = [];
                foreach ($item->getCertificate()?->getList() ?? [] as $certificate) {
                    $out[$name][] = [
                        'id' => (string) $certificate->getCertId(),
                        'sign_algo' => (string) $certificate->getSignAlgo(),
                        'expire_time' => (string) $certificate->getExpireTime(),
                    ];
                }
            }
            $offset += 100;
        } while (count($items) === 100);

        return $out;
    }

    /** @param list<array{id:string,sign_algo:string,expire_time:string}> $certificates */
    private function eoDomainHasCertificate(array $certificates, string $certId): bool
    {
        foreach ($certificates as $certificate) {
            if ($certificate['id'] === $certId) {
                return true;
            }
        }

        return false;
    }

    private function eoCertificateAlgorithm(string $certificate): string
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

    /** @return list<string> */
    private function normalizeEoDomains(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : (preg_split('/[\r\n,，;；]+/u', (string) $raw) ?: []);

        return array_values(array_unique(array_filter(array_map(fn ($item): string => trim((string) $item), $items))));
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL 全局服务、TEO 接口无区域维度（certimate 亦传空 region），region 取空即可
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'teo' => new TeoClient($cred, '', $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
