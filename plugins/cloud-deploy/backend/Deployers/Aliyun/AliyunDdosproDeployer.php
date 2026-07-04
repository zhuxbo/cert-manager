<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Ddoscoo;
use AlibabaCloud\SDK\Ddoscoo\V20200101\Models\AssociateWebCertRequest;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
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
 * **简化**：仅实现 certimate DOMAIN_MATCH_PATTERN_EXACT 核心路径（单域名直接 AssociateWebCert）。
 * 不做：wildcard/certsan 匹配模式（需 DescribeDomains 拉全部域名再 IsMatch 过滤遍历，留后续）。
 *
 * region：DDoS 高防服务自身 region（config.region，定 ddoscoo endpoint）。certimate createSDKClient 按
 * region 选 `ddoscoo.{region}.aliyuncs.com`（空回落 cn-hangzhou）。证书的 CAS region 编在 CertIdentifier
 * 里、CAS 全局，二者独立。
 *
 * SDK：alibabacloud/ddoscoo-20200101 ^4（openapi-core 运行时，client extends Darabonba\OpenApi\OpenApiClient），
 * 与 apigw/esa 同代；脱敏走 AliyunErrorSanitizer 的 AlibabaCloudException 分支。单测全程 mock client，不触达
 * HTTP；真实环境建议做一次冒烟验证。
 */
class AliyunDdosproDeployer extends AbstractDeployer
{
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
            ['key' => 'domain', 'label' => '网站域名', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // CAS 全局，上传不需要 region；复用 deployer 注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 certIdentifier）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $region = isset($config['region']) ? (string) $config['region'] : '';
        $certIdentifier = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $domain, $certIdentifier) {
            /** @var Ddoscoo $client */
            $client = $this->makeClient('ddoscoo', $credentials + ['region' => $region]);
            $client->associateWebCert(new AssociateWebCertRequest([
                'domain' => $domain,
                'certIdentifier' => $certIdentifier,
            ]));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $ak = $credentials['access_key_id'] ?? '';
        $sk = $credentials['access_key_secret'] ?? '';

        return match ($kind) {
            'cas' => new Cas(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => 'cas.aliyuncs.com',
            ])),
            // 接入点：ddoscoo.{region}.aliyuncs.com（空 region 回落 cn-hangzhou，对齐 certimate）
            'ddoscoo' => new Ddoscoo(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => $this->endpointForRegion($credentials['region'] ?? ''),
            ])),
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
