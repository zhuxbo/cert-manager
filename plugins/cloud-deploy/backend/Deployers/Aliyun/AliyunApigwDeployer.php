<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\APIG\V20240327\APIG;
use AlibabaCloud\SDK\APIG\V20240327\Models\GetDomainRequest;
use AlibabaCloud\SDK\APIG\V20240327\Models\ListDomainsRequest;
use AlibabaCloud\SDK\APIG\V20240327\Models\UpdateDomainRequest;
use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\CloudAPI\V20160714\CloudAPI;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\DescribeApiGroupRequest;
use AlibabaCloud\SDK\CloudAPI\V20160714\Models\SetDomainCertificateRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertificateDeliveryMode;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Plugins\CloudDeploy\Deployers\Contracts\SelectsCertificateDeliveryMode;
use Throwable;

/**
 * 阿里云 API 网关（云原生 APIG 与传统 CloudAPI 双栈）。
 *
 * 对齐 certimate aliyun-apigw 的 **cloudnative** 栈：证书先经 CAS 上传拿 CertIdentifier（走
 * RemoteCertStore 去重，store_kind=cas），再按域名找到 apig 域名 ID（ListDomains nameLike + 精确匹配），
 * GetDomain 读回既有 TLS 配置，UpdateDomain 把 CertIdentifier 绑上去（HTTPS）。
 *
 * 【双栈说明】
 * certimate 同一 provider 支持两栈，靠 config.serviceType ∈ {cloudnative, traditional} 区分：
 *   - cloudnative（本类）：新版 APIG（apig-20240327）+ UpdateDomain(CertIdentifier) —— **证书服务型**（CAS 上传）。
 *   - traditional：经典版 API 网关（cloudapi-20160714）+ SetDomainCertificate(PEM 内联) —— **内联型**（不走 CAS）。
 * Task 1 的 `SelectsCertificateDeliveryMode` 允许同一 endpoint 按 target config 在 RemoteStore（cloudnative）
 * 与 Inline（traditional）之间安全切换：前者走 CAS 上传，后者只在 job 内将 PEM 传给 CloudAPI。
 *
 * 与 WAF/GA 同：apig 的 UpdateDomain.CertIdentifier 吃**完整 CertIdentifier 字符串**（"{certId}-{region}"），
 * **不**拆 certId+region（对齐 certimate：CertIdentifier: tea.String(cloudCertId)，cloudCertId 即 upres
 * 的 CertIdentifier 原样）。故 bind 把 remote_cert_id 原样作 certIdentifier，无需 ParsesCasCertIdentifier。
 *
 * get-then-update（不可省）：UpdateDomain 是全量覆盖，certimate 先 GetDomain 读回 forceHttps/mTLSEnabled/
 * http2Option/tlsMin/tlsMax/tlsCipherSuitesConfig 原样回填，避免把域名既有 TLS 策略重置。本类同款回填。
 *
 * 两栈均支持 Certimate 的 exact/wildcard/certsan 规则；传统网关只更新 DomainBindingStatus=BINDING，
 * 云原生 wildcard/certsan 只从 Published 域名中筛选。
 *
 * apig-20240327 与 cloudapi-20160714 均使用新一代 openapi-core/darabonba ^1；CAS/WAF 等仍保留旧 Tea
 * 运行时，异常统一经 AliyunErrorSanitizer 净化。单测 mock 云端调用，另有真实 client construction smoke。
 *
 * region：apig 服务自身 region（config.region，定 apig endpoint）；证书的 CAS region 编在 CertIdentifier
 * 里、CAS 全局，二者独立。region 缺省回落 cn-hangzhou（对齐 certimate createSDKClients）。
 */
class AliyunApigwDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial, SelectsCertificateDeliveryMode
{
    use BuildsAliyunConfig, MatchesAliyunDomains;

    /** 服务类型：云原生新版 APIG（本类实现）。 */
    private const SERVICE_TYPE_CLOUDNATIVE = 'cloudnative';

    /** 服务类型：经典版 API 网关（CloudAPI + 内联 PEM）。 */
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
            ['key' => 'service_type', 'label' => '服务类型', 'type' => 'select', 'required' => false, 'default' => self::SERVICE_TYPE_CLOUDNATIVE, 'options' => [
                ['label' => '云原生 API 网关', 'value' => self::SERVICE_TYPE_CLOUDNATIVE],
                ['label' => '传统 API 网关', 'value' => self::SERVICE_TYPE_TRADITIONAL],
            ]],
            ['key' => 'gateway_id', 'label' => '云原生 API 网关实例 ID', 'type' => 'string', 'required' => false,
                'required_when' => ['key' => 'service_type', 'equals' => self::SERVICE_TYPE_CLOUDNATIVE],
                'visible_when' => ['key' => 'service_type', 'equals' => self::SERVICE_TYPE_CLOUDNATIVE]],
            ['key' => 'group_id', 'label' => '传统 API 网关分组 ID', 'type' => 'string', 'required' => false,
                'required_when' => ['key' => 'service_type', 'equals' => self::SERVICE_TYPE_TRADITIONAL],
                'visible_when' => ['key' => 'service_type', 'equals' => self::SERVICE_TYPE_TRADITIONAL]],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'select', 'required' => false, 'default' => 'exact', 'options' => [
                ['label' => '精确匹配', 'value' => 'exact'],
                ['label' => '泛域名匹配', 'value' => 'wildcard'],
                ['label' => '证书 SAN 匹配', 'value' => 'certsan'],
            ]],
            // exact/wildcard 由统一 domain_match_pattern 校验要求；certsan 可省略。
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => false],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certificateDeliveryMode(array $config): CertificateDeliveryMode
    {
        return $this->serviceType($config) === self::SERVICE_TYPE_TRADITIONAL
            ? CertificateDeliveryMode::Inline
            : CertificateDeliveryMode::RemoteStore;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // CAS 全局，上传不需要 region；复用 deployer 注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials), $this->casRegion($config));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 certIdentifier）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{service_type?:string,gateway_id?:string,group_id?:string,domain_match_pattern?:string,domain?:string,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $serviceType = $this->serviceType($config);
        $region = isset($config['region']) ? (string) $config['region'] : '';
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        if (! in_array($pattern, ['exact', 'wildcard', 'certsan'], true)) {
            $this->fail("不支持的 API 网关域名匹配模式: $pattern");
        }

        if ($serviceType === self::SERVICE_TYPE_TRADITIONAL) {
            $groupId = (string) $this->requireConfig($config, 'group_id');
            $domain = $pattern === 'certsan' ? '' : (string) $this->requireConfig($config, 'domain');
            $client = $this->makeClient('cloudapi', $credentials + ['region' => $region]);
            $candidates = $this->guardSdk(fn () => $this->traditionalDomains($client, $groupId));
            $domains = $this->matchedDomains($candidates, $pattern, $domain, (string) $certRef['cert']);
            if ($domains === []) {
                $this->fail('未找到匹配的 API 网关域名');
            }
            $certificate = rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']);
            $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
            $this->guardSdk(function () use ($client, $groupId, $domains, $certName, $certificate, $certRef): void {
                foreach ($domains as $domain) {
                    $client->setDomainCertificate(new SetDomainCertificateRequest([
                        'groupId' => $groupId,
                        'domainName' => $domain,
                        'certificateName' => $certName,
                        'certificateBody' => $certificate,
                        'certificatePrivateKey' => $certRef['key'],
                    ]));
                }
            });

            return;
        }

        $gatewayId = (string) $this->requireConfig($config, 'gateway_id');
        $domain = $pattern === 'certsan' ? '' : (string) $this->requireConfig($config, 'domain');
        $certIdentifier = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        if ($certIdentifier === '') {
            $this->fail('缺少远端证书标识');
        }
        $client = $this->makeClient('apig', $credentials + ['region' => $region]);
        $domains = $pattern === 'exact' || ($pattern === 'wildcard' && ! str_starts_with($domain, '*.'))
            ? [$domain]
            : $this->matchedDomains(
                $this->guardSdk(fn () => $this->cloudNativeDomains($client, $gatewayId)),
                $pattern,
                $domain,
                is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '',
            );
        if ($domains === []) {
            $this->fail('未找到匹配的 API 网关域名');
        }
        foreach ($domains as $matchedDomain) {
            $this->bindCloudNativeDomain($client, $gatewayId, $matchedDomain, $certIdentifier);
        }
    }

    /** 规范化并校验服务类型，缺省保持既有 cloudnative 兼容性。 */
    private function serviceType(array $config): string
    {
        $serviceType = strtolower((string) ($config['service_type'] ?? self::SERVICE_TYPE_CLOUDNATIVE));
        if ($serviceType === '') {
            return self::SERVICE_TYPE_CLOUDNATIVE;
        }
        if (! in_array($serviceType, [self::SERVICE_TYPE_CLOUDNATIVE, self::SERVICE_TYPE_TRADITIONAL], true)) {
            $this->fail("不支持的 API 网关服务类型: $serviceType");
        }

        return $serviceType;
    }

    /** @return list<string> 仅传统 API 网关已 BINDING 的自定义域名可更新证书。 */
    private function traditionalDomains(CloudAPI $client, string $groupId): array
    {
        $response = $client->describeApiGroup(new DescribeApiGroupRequest(['groupId' => $groupId]))->toMap();
        $items = $response['body']['CustomDomains']['DomainItem'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_array($item)
                && is_string($item['DomainBindingStatus'] ?? null)
                && strcasecmp($item['DomainBindingStatus'], 'BINDING') === 0
                ? (string) ($item['DomainName'] ?? '')
                : '',
            $items,
        ), static fn (string $domain): bool => $domain !== ''));
    }

    /** @return list<string> 云原生 APIG 仅把已发布域名作为 wildcard/certsan 候选。 */
    private function cloudNativeDomains(APIG $client, string $gatewayId): array
    {
        $domains = [];
        for ($pageNumber = 1; ; $pageNumber++) {
            $response = $client->listDomains(new ListDomainsRequest([
                'gatewayId' => $gatewayId,
                'pageNumber' => $pageNumber,
                'pageSize' => self::PAGE_SIZE,
            ]));
            $items = $this->modelField($this->modelField($response, 'body'), 'data');
            $items = $this->modelField($items, 'items');
            if (! is_array($items)) {
                break;
            }
            foreach ($items as $item) {
                $name = $this->modelField($item, 'name');
                if (is_string($name)
                    && strcasecmp((string) $this->modelField($item, 'status'), 'Published') === 0) {
                    $domains[] = $name;
                }
            }
            if (count($items) < self::PAGE_SIZE) {
                break;
            }
        }

        return $domains;
    }

    /** @return list<string> */
    private function matchedDomains(array $candidates, string $pattern, string $domain, string $certPem): array
    {
        return array_values(array_filter($candidates, function (string $candidate) use ($pattern, $domain, $certPem): bool {
            return match ($pattern) {
                'exact' => strcasecmp($candidate, $domain) === 0,
                'wildcard' => $this->hostnameMatches($domain, $candidate),
                'certsan' => $this->certificateMatches($certPem, $candidate),
                default => false,
            };
        }));
    }

    /** 云原生 APIG 的更新是全量覆盖，必须先读回既有 TLS 字段。 */
    private function bindCloudNativeDomain(APIG $client, string $gatewayId, string $domain, string $certIdentifier): void
    {
        $domainId = $this->guardSdk(fn () => $this->findDomainId($client, $gatewayId, $domain));
        if ($domainId === null) {
            $this->fail("阿里云 API 网关未找到域名 $domain");
        }

        $this->guardSdk(function () use ($client, $domainId, $certIdentifier): void {
            $response = $client->getDomain($domainId, new GetDomainRequest);
            $existing = $this->modelField($this->modelField($response, 'body'), 'data');
            $client->updateDomain($domainId, new UpdateDomainRequest([
                'protocol' => 'HTTPS',
                'forceHttps' => $this->modelField($existing, 'forceHttps'),
                'mTLSEnabled' => $this->modelField($existing, 'mTLSEnabled'),
                'http2Option' => $this->modelField($existing, 'http2Option'),
                'tlsMin' => $this->modelField($existing, 'tlsMin'),
                'tlsMax' => $this->modelField($existing, 'tlsMax'),
                'tlsCipherSuitesConfig' => $this->modelField($existing, 'tlsCipherSuitesConfig'),
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

            $items = $this->modelField($this->modelField($this->modelField($resp, 'body'), 'data'), 'items');
            if (! is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                $name = $this->modelField($item, 'name');
                if (! is_string($name) || strcasecmp($name, $domain) !== 0) {
                    continue;
                }
                $id = $this->modelField($item, 'domainId');
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
            $pageNumber++;
        }

        return null;
    }

    /**
     * SDK 生成模型把可选响应字段标为非空属性，但响应缺省时属性实际未初始化。
     * 经 get_object_vars 读取可同时处理缺省字段、保留空响应保护并避免 SDK PHPDoc 的静态误判。
     */
    private function modelField(mixed $model, string $field): mixed
    {
        return is_object($model) ? (get_object_vars($model)[$field] ?? null) : null;
    }

    protected function makeClient(string $kind, array $credentials): object
    {

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            // 接入点：apig.{region}.aliyuncs.com（空 region 回落 cn-hangzhou，对齐 certimate）
            'apig' => new APIG($this->aliyunConfig($credentials, $this->endpointForRegion($credentials['region'] ?? ''))),
            'cloudapi' => new CloudAPI($this->aliyunConfig($credentials, $this->traditionalEndpointForRegion($credentials['region'] ?? ''))),
            default => throw new \LogicException("未知的阿里云 SDK 客户端类型: $kind"),
        };
    }

    /** apig 接入点：region 为空回落杭州，否则 apig.{region}.aliyuncs.com。 */
    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'apig.cn-hangzhou.aliyuncs.com' : "apig.$region.aliyuncs.com";
    }

    /** 传统 API 网关接入点：region 为空回落杭州，对齐 Certimate createSDKClientCAPI。 */
    private function traditionalEndpointForRegion(string $region): string
    {
        return $region === '' ? 'apigateway.cn-hangzhou.aliyuncs.com' : "apigateway.$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
