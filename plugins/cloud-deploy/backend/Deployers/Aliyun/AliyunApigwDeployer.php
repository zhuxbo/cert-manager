<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\APIG\V20240327\APIG;
use AlibabaCloud\SDK\APIG\V20240327\Models\GetDomainRequest;
use AlibabaCloud\SDK\APIG\V20240327\Models\ListDomainsRequest;
use AlibabaCloud\SDK\APIG\V20240327\Models\UpdateDomainRequest;
use AlibabaCloud\SDK\Cas\V20200407\Cas;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 API 网关（云原生「新版 APIG」apig-20240327，证书服务型）。
 *
 * 对齐 certimate aliyun-apigw 的 **cloudnative** 栈：证书先经 CAS 上传拿 CertIdentifier（走
 * RemoteCertStore 去重，store_kind=cas），再按域名找到 apig 域名 ID（ListDomains nameLike + 精确匹配），
 * GetDomain 读回既有 TLS 配置，UpdateDomain 把 CertIdentifier 绑上去（HTTPS）。
 *
 * 【双栈说明 — 为何只做 cloudnative】
 * certimate 同一 provider 支持两栈，靠 config.serviceType ∈ {cloudnative, traditional} 区分：
 *   - cloudnative（本类）：新版 APIG（apig-20240327）+ UpdateDomain(CertIdentifier) —— **证书服务型**（CAS 上传）。
 *   - traditional：经典版 API 网关（cloudapi-20160714）+ SetDomainCertificate(PEM 内联) —— **内联型**（不走 CAS）。
 * 本插件架构里「证书服务型 vs 内联型」由 deployer 的 usesRemoteCertStore() 决定，而该方法**在 CloudDeployJob
 * 取 config 之前调用**（无 config 入参，见 Jobs/CloudDeployJob.php），故单个 deployer 无法按 serviceType 配置
 * 动态切换上传策略。两栈策略恰好相反（cloudnative=CAS 上传 / traditional=内联 PEM），无法塞进同一 deployer。
 * 干净的双栈支持需注册两个独立 deployer（如本 `apigw` + 未来 `apigw-classic`），各自固定 usesRemoteCertStore。
 * 本批先实现 cloudnative —— 它是 certimate 测试默认（SERVICETYPE="cloudnative"）、阿里推荐的新版产品，且契合
 * 既有 CAS 上传模板（WAF/GA/DCDN/VOD）。configSchema 保留 service_type 字段为未来留好结构；bind 内 gate：
 * service_type 非 cloudnative 时给出明确「暂未实现 traditional 栈」业务错误，不静默走错路径。
 *
 * 与 WAF/GA 同：apig 的 UpdateDomain.CertIdentifier 吃**完整 CertIdentifier 字符串**（"{certId}-{region}"），
 * **不**拆 certId+region（对齐 certimate：CertIdentifier: tea.String(cloudCertId)，cloudCertId 即 upres
 * 的 CertIdentifier 原样）。故 bind 把 remote_cert_id 原样作 certIdentifier，无需 ParsesCasCertIdentifier。
 *
 * get-then-update（不可省）：UpdateDomain 是全量覆盖，certimate 先 GetDomain 读回 forceHttps/mTLSEnabled/
 * http2Option/tlsMin/tlsMax/tlsCipherSuitesConfig 原样回填，避免把域名既有 TLS 策略重置。本类同款回填。
 *
 * 简化（对齐已交付端点口径）：仅实现 exact domain 核心路径；不做 certimate 的 DomainMatchPattern
 * （wildcard/certsan）/遍历域名（留后续）。
 *
 * 注意：apig-20240327 是**新一代 darabonba 运行时**（openapi-core/darabonba ^1）生成，与既有 CAS/WAF 等
 * 老运行时（darabonba-openapi ^0.2）在 `Darabonba\OpenApi\*` 命名空间存在文件级共存（详见报告/插件 composer）。
 * 单测全程 mock APIG client，不触达其 callApi/HTTP 路径；真实环境调用建议做一次冒烟验证。
 *
 * region：apig 服务自身 region（config.region，定 apig endpoint）；证书的 CAS region 编在 CertIdentifier
 * 里、CAS 全局，二者独立。region 缺省回落 cn-hangzhou（对齐 certimate createSDKClients）。
 */
class AliyunApigwDeployer extends AbstractDeployer
{
    /** 服务类型：云原生新版 APIG（本类实现）。 */
    private const SERVICE_TYPE_CLOUDNATIVE = 'cloudnative';

    /** 服务类型：经典版 API 网关（暂未实现，留后续独立 deployer）。 */
    private const SERVICE_TYPE_TRADITIONAL = 'traditional';

    /** ListDomains 分页大小（对齐 certimate listDomainsPageSize=10）。 */
    private const PAGE_SIZE = 10;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'apigw';
    }

    public function label(): string
    {
        return '阿里云 API 网关';
    }

    public function configSchema(): array
    {
        return [
            // 服务类型：当前仅 cloudnative（新版 APIG）；traditional（经典版）留后续。默认 cloudnative。
            ['key' => 'service_type', 'label' => '服务类型', 'type' => 'string', 'required' => false],
            // 云原生 API 网关实例 ID（service_type=cloudnative 必填）
            ['key' => 'gateway_id', 'label' => 'API 网关实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
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
     * @param  array{service_type?:string,gateway_id:string,domain:string,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // 服务类型 gate：仅 cloudnative；traditional/未知给明确业务错误，绝不静默走错栈
        $serviceType = (string) ($config['service_type'] ?? self::SERVICE_TYPE_CLOUDNATIVE);
        if ($serviceType === '') {
            $serviceType = self::SERVICE_TYPE_CLOUDNATIVE;
        }
        if ($serviceType === self::SERVICE_TYPE_TRADITIONAL) {
            $this->fail('阿里云 API 网关「经典版（traditional）」暂未实现，请使用云原生新版 APIG（cloudnative）');
        }
        if ($serviceType !== self::SERVICE_TYPE_CLOUDNATIVE) {
            $this->fail("不支持的 API 网关服务类型: $serviceType");
        }

        $gatewayId = (string) $this->requireConfig($config, 'gateway_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        $region = isset($config['region']) ? (string) $config['region'] : '';
        $certIdentifier = (string) $certRef;

        $client = $this->makeClient('apig', $credentials + ['region' => $region]);

        // 1. 按域名找 apig 域名 ID（ListDomains nameLike 分页 + 精确匹配 name）。SDK 调用经 guardSdk 脱敏；
        //    「未找到」是**业务结果**（findDomainId 返回 null，不在闭包内抛），故 null 判定与 fail() 放 guardSdk 外
        //    ——否则业务 RuntimeException 会被 guardSdk 的 catch(Throwable) 当 SDK 异常二次脱敏（同 CAS 上传器教训）。
        $domainId = $this->guardSdk(fn () => $this->findDomainId($client, $gatewayId, $domain));
        if ($domainId === null) {
            $this->fail("阿里云 API 网关未找到域名 $domain");
        }

        $this->guardSdk(function () use ($client, $domainId, $certIdentifier) {
            /** @var APIG $client */
            // 2. GetDomain 读回既有 TLS 配置（UpdateDomain 全量覆盖，需原样回填，避免重置 TLS 策略）
            $existing = $client->getDomain($domainId, new GetDomainRequest)->body?->data;

            // 3. UpdateDomain 绑证书（HTTPS + 完整 CertIdentifier + 回填既有 TLS 字段）
            $client->updateDomain($domainId, new UpdateDomainRequest([
                'protocol' => 'HTTPS',
                'forceHttps' => $existing?->forceHttps,
                'mTLSEnabled' => $existing?->mTLSEnabled,
                'http2Option' => $existing?->http2Option,
                'tlsMin' => $existing?->tlsMin,
                'tlsMax' => $existing?->tlsMax,
                'tlsCipherSuitesConfig' => $existing?->tlsCipherSuitesConfig,
                'certIdentifier' => $certIdentifier,
            ]));
        });
    }

    /**
     * 按域名精确匹配找 apig 域名 ID（对齐 certimate findCloudNativeDomainIdByDomain）。
     * ListDomains 以 nameLike=domain 缩小候选，分页遍历，name 大小写不敏感精确相等即命中。
     * 未找到返回 null（调用方在 guardSdk 外判 null 抛业务错误，避免被 SDK 脱敏吞掉）。
     */
    private function findDomainId(APIG $client, string $gatewayId, string $domain): ?string
    {
        $pageNumber = 1;
        while (true) {
            $resp = $client->listDomains(new ListDomainsRequest([
                'gatewayId' => $gatewayId,
                'nameLike' => $domain,
                'pageNumber' => $pageNumber,
                'pageSize' => self::PAGE_SIZE,
            ]));

            $data = $resp->body?->data;
            $items = $data?->items;
            if (! is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                if (is_string($item->name) && strcasecmp($item->name, $domain) === 0) {
                    $id = $item->domainId;
                    if (is_string($id) && $id !== '') {
                        return $id;
                    }
                }
            }

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
            $pageNumber++;
        }

        return null;
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
            // 接入点：apig.{region}.aliyuncs.com（空 region 回落 cn-hangzhou，对齐 certimate）
            'apig' => new APIG(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => $this->endpointForRegion($credentials['region'] ?? ''),
            ])),
        };
    }

    /** apig 接入点：region 为空回落杭州，否则 apig.{region}.aliyuncs.com。 */
    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'apig.cn-hangzhou.aliyuncs.com' : "apig.$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
