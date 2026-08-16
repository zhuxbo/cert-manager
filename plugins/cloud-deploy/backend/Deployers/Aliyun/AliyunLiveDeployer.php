<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Live\V20161101\Live;
use AlibabaCloud\SDK\Live\V20161101\Models\DescribeLiveUserDomainsRequest;
use AlibabaCloud\SDK\Live\V20161101\Models\SetLiveDomainCertificateRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 阿里云直播（内联型）：直播 SetLiveDomainCertificate 只支持 CertType=upload 直传 PEM
 * （SDK 请求体无 CertId/CertRegion 字段，不支持 CAS 引用），故 usesRemoteCertStore=false、certUploader=null，
 * 与阿里云 CDN 同型，对齐 certimate aliyun-live。
 *
 * 每次绑定生成唯一 CertName（阿里云命名规则：字母/数字/下划线），支持 exact/wildcard/certsan 批量匹配。
 */
class AliyunLiveDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig, MatchesAliyunDomains;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'live';
    }

    public function label(): string
    {
        return '阿里云直播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => false],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string, mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = $this->requireConfig($config, 'domain');
        $region = (string) ($config['region'] ?? '');
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        // 证书 + 中间证书拼成完整链上传
        $sslPub = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $domain, $region, $pattern, $sslPub, $certRef, $certName) {
            /** @var Live $client */
            $client = $this->makeClient('live', $credentials + ['region' => $region]);
            $domains = $pattern === 'exact' || ($pattern === 'wildcard' && ! str_starts_with($domain, '*.'))
                ? [$domain]
                : $this->matchingDomains($client, $domain, $pattern, (string) $certRef['cert'], $region, (string) ($credentials['resource_group_id'] ?? ''));
            foreach ($domains as $matchedDomain) {
                $client->setLiveDomainCertificate(new SetLiveDomainCertificateRequest([
                    'domainName' => $matchedDomain,
                    'certName' => $certName,
                    'certType' => 'upload',
                    'SSLProtocol' => 'on',
                    'SSLPub' => $sslPub,
                    'SSLPri' => $certRef['key'],
                ]));
            }
        });
    }

    /** @return list<string> */
    private function matchingDomains(Live $client, string $domain, string $pattern, string $certPem, string $region, string $resourceGroupId): array
    {
        if (! in_array($pattern, ['wildcard', 'certsan'], true)) {
            $this->fail("Aliyun Live 不支持的域名匹配模式: $pattern");
        }
        $matched = [];
        for ($page = 1; ; $page++) {
            $request = ['regionName' => $region, 'domainStatus' => 'online', 'pageNumber' => $page, 'pageSize' => 50];
            if ($resourceGroupId !== '') {
                $request['resourceGroupId'] = $resourceGroupId;
            }
            $items = $client->describeLiveUserDomains(new DescribeLiveUserDomainsRequest($request))->body?->domains?->pageData ?? [];
            foreach ($items as $item) {
                $name = (string) $item->domainName;
                if (($pattern === 'wildcard' && $this->hostnameMatches($domain, $name))
                    || ($pattern === 'certsan' && $this->certificateMatches($certPem, $name))) {
                    $matched[] = $name;
                }
            }
            if (count($items) < 50) {
                break;
            }
        }
        if ($matched === []) {
            $this->fail('未找到匹配的直播域名');
        }

        return $matched;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $region = (string) ($credentials['region'] ?? '');
        $globalRegions = ['', 'cn-qingdao', 'cn-beijing', 'cn-shanghai', 'cn-shenzhen', 'ap-northeast-1', 'ap-southeast-5', 'me-central-1'];
        $endpoint = in_array($region, $globalRegions, true) ? 'live.aliyuncs.com' : "live.$region.aliyuncs.com";

        return match ($kind) {
            'live' => new Live($this->aliyunConfig($credentials, $endpoint)),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
