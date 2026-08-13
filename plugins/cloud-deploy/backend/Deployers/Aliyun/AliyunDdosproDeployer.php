<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Ddoscoo;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Models\AssociateWebCertRequest;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Models\DescribeDomainsRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 阿里云 DDoS 高防（Anti-DDoS Pro/Premium，证书服务型，复用 CAS 上传器）。
 *
 * 证书先经 AliyunCasUploader 上传拿 CertIdentifier（走 RemoteCertStore 去重，store_kind=cas），再调
 * ddoscoo.AssociateWebCert 把证书关联到网站业务转发规则的域名。
 *
 * 与 WAF/GA/APIG 同：ddoscoo 的 AssociateWebCert.CertIdentifier 吃**完整 CertIdentifier 字符串**
 * （"{certId}-{region}"），**不**拆 certId+region（对齐 certimate aliyun-ddospro：
 * `certId := upres.ExtendedData["CertIdentifier"].(string)` 原样塞 AssociateWebCert.CertIdentifier）。
 * 故 bind 把 remote_cert_id 原样作 certIdentifier，无需 ParsesCasCertIdentifier。
 *
 * 支持 exact 与 wildcard；wildcard 经 DescribeDomains 过滤后逐域名 AssociateWebCert。
 *
 * region：DDoS 高防服务自身 region（config.region，定 ddoscoo endpoint）。certimate createSDKClient 按
 * region 选 `ddoscoo.{region}.aliyuncs.com`（空回落 cn-hangzhou）。证书的 CAS region 编在 CertIdentifier
 * 里；CAS 上传 region 与 DDoS 服务 region 分别按 config 传入。
 *
 * SDK：alibabacloud/ddoscoo-20200101 ^4（openapi-core 运行时，client extends Darabonba\OpenApi\OpenApiClient），
 * 与 apigw/esa 同代；脱敏走 AliyunErrorSanitizer 的 AlibabaCloudException 分支。单测全程 mock client，不触达
 * HTTP；真实环境建议做一次冒烟验证。
 */
class AliyunDdosproDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use BuildsAliyunConfig, MatchesAliyunDomains;
    use MatchesCertificateHostnames;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'ddospro';
    }

    public function label(): string
    {
        return '阿里云 DDoS 高防';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '网站域名', 'type' => 'string', 'required' => false],
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
        // CAS 全局，上传不需要 region；复用 deployer 注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials), $this->casRegion($config));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 certIdentifier）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array<string, mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = $pattern === 'certsan' ? (string) ($config['domain'] ?? '') : (string) $this->requireConfig($config, 'domain');
        $region = isset($config['region']) ? (string) $config['region'] : '';
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $certIdentifier = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $domain, $pattern, $certificate, $certIdentifier) {
            /** @var Ddoscoo $client */
            $client = $this->makeClient('ddoscoo', array_replace($credentials, ['region' => $region]));
            if (! in_array($pattern, ['exact', 'wildcard', 'certsan'], true)) {
                $this->fail("Aliyun DDoS 不支持的域名匹配模式: $pattern");
            }
            $domains = $pattern === 'exact' || ($pattern === 'wildcard' && ! str_starts_with($domain, '*.'))
                ? [$domain]
                : array_values(array_filter(
                    $client->describeDomains(new DescribeDomainsRequest(
                        ($credentials['resource_group_id'] ?? '') !== ''
                            ? ['resourceGroupId' => (string) $credentials['resource_group_id']]
                            : [],
                    ))->body?->domains ?? [],
                    fn ($candidate) => $pattern === 'certsan'
                        ? $this->certificateMatchesHostname($certificate, (string) $candidate)
                        : $this->hostnameMatches($domain, (string) $candidate),
                ));
            if ($domains === []) {
                $this->fail('未找到匹配的 DDoS 域名');
            }
            foreach ($domains as $matchedDomain) {
                $client->associateWebCert(new AssociateWebCertRequest([
                    'domain' => (string) $matchedDomain,
                    'certIdentifier' => $certIdentifier,
                ]));
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            // 接入点：ddoscoo.{region}.aliyuncs.com（空 region 回落 cn-hangzhou，对齐 certimate）
            'ddoscoo' => new Ddoscoo($this->aliyunConfig($credentials, $this->endpointForRegion($credentials['region'] ?? ''))),
        };
    }

    /** ddoscoo 接入点：region 为空回落杭州，否则 ddoscoo.{region}.aliyuncs.com。 */
    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'ddoscoo.cn-hangzhou.aliyuncs.com' : "ddoscoo.$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
