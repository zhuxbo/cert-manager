<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\ESA\V20240910\ESA;
use AlibabaCloud\SDK\ESA\V20240910\Models\ListCustomHostnamesRequest;
use AlibabaCloud\SDK\ESA\V20240910\Models\UpdateCustomHostnameRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 阿里云 ESA SaaS（边缘安全加速 SaaS 域名，证书服务型，复用 CAS 上传器）。
 *
 * 对齐 certimate aliyun-esa-saas：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），再
 * 分页 esa.ListCustomHostnames(SiteId) 找到目标 SaaS 域名，调 esa.UpdateCustomHostname 以 CertType=cas
 * + CasId（**纯数字 certId**）+ CasRegion 把证书绑定到该域名。
 *
 * 支持 exact 与 wildcard 批量匹配；跳过 pending/conflicted/offline 状态的域名。
 *
 * config：site_id（必填）/ domain（必填，支持泛域名）/ region（选填，ESA endpoint，空回落 cn-hangzhou）。
 */
class AliyunEsaSaasDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use BuildsAliyunConfig, MatchesAliyunDomains;
    use MatchesCertificateHostnames;
    use ParsesCasCertIdentifier;

    /** 列表分页每页大小。 */
    protected int $pageSize = 100;

    /** 列表最大翻页数（防御性上限）。 */
    protected int $maxPages = 100;

    /** 跳过的域名状态（对齐 certimate ignoredStatuses）。 */
    private const IGNORED_STATUSES = ['pending', 'conflicted', 'offline'];

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'esasaas';
    }

    public function label(): string
    {
        return '阿里云 ESA SaaS';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'site_id', 'label' => 'ESA 站点 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => 'SaaS 域名', 'type' => 'string', 'required' => false],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => false],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
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
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，拆出数字 certId 作 CasId、region 作 CasRegion）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{site_id:int|string,domain:string,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $siteIdRaw = $this->requireConfig($config, 'site_id');
        if (! ctype_digit((string) $siteIdRaw)) {
            $this->fail('ESA 站点 ID（site_id）必须为数字');
        }
        $siteId = (int) $siteIdRaw;
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        $region = isset($config['region']) ? (string) $config['region'] : '';
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $remoteCertId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        [$certId, $certRegion] = $this->parseCertIdentifier($remoteCertId);

        /** @var ESA $client */
        $client = $this->makeClient('esa', $credentials + ['region' => $region]);

        $hostnameIds = $this->guardSdk(fn () => $this->findHostnameIds($client, $siteId, $domain, $pattern, $certificate));
        if ($hostnameIds === []) {
            $this->fail("未找到 ESA SaaS 域名：$domain");
        }

        $this->guardSdk(function () use ($client, $hostnameIds, $certId, $certRegion) {
            foreach ($hostnameIds as $hostnameId) {
                $client->updateCustomHostname(new UpdateCustomHostnameRequest([
                    'hostnameId' => $hostnameId,
                    'sslFlag' => 'on',
                    'certType' => 'cas',
                    'casId' => $certId,
                    'casRegion' => $certRegion,
                ]));
            }
        });
    }

    /**
     * 分页查 SaaS 域名列表，返回精确匹配 $domain 的 HostnameId（跳过 ignored 状态）；未找到返回 null。
     *
     * @param  ESA  $client
     */
    protected function findHostnameIds(object $client, int $siteId, string $domain, string $pattern, string $certificate = ''): array
    {
        if (! in_array($pattern, ['exact', 'wildcard', 'certsan'], true)) {
            $this->fail("Aliyun ESA SaaS 不支持的域名匹配模式: $pattern");
        }
        $ids = [];
        for ($page = 1; $page <= $this->maxPages; $page++) {
            $resp = $client->listCustomHostnames(new ListCustomHostnamesRequest([
                'siteId' => $siteId,
                'pageNumber' => $page,
                'pageSize' => $this->pageSize,
            ]));
            $hostnames = $resp->body?->hostnames ?? [];

            foreach ($hostnames as $item) {
                if (in_array((string) $item->status, self::IGNORED_STATUSES, true)) {
                    continue;
                }
                $hostname = (string) $item->hostname;
                if ($pattern === 'certsan') {
                    $matched = $this->certificateMatchesHostname($certificate, $hostname);
                } elseif ($pattern === 'exact' || ! str_starts_with($domain, '*.')) {
                    $matched = $hostname === $domain;
                } else {
                    $matched = $this->hostnameMatches($domain, $hostname);
                }
                if ($matched) {
                    $ids[] = (int) $item->hostnameId;
                }
            }

            if (count($hostnames) < $this->pageSize) {
                break;
            }
        }

        return $ids;
    }

    protected function makeClient(string $kind, array $credentials): object
    {

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            // 接入点：esa.{region}.aliyuncs.com（空 region 回落 cn-hangzhou，对齐 certimate + esa 端点）
            'esa' => new ESA($this->aliyunConfig($credentials, $this->endpointForRegion($credentials['region'] ?? ''))),
        };
    }

    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'esa.cn-hangzhou.aliyuncs.com' : "esa.$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
