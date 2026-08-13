<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\DescribeCloudResourceAccessPortDetailsRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\DescribeDefaultHttpsRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\DescribeDomainDetailRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\DescribeResourceInstanceCertsRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyCloudResourceCertRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyCloudResourceCertRequest\certificates as cloudCertificates;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDefaultHttpsRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDomainRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDomainRequest\listen as modifyListen;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDomainRequest\redirect as modifyRedirect;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDomainRequest\redirect\requestHeaders as modifyRequestHeaders;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Wafopenapi;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 WAF 3.0（证书服务型，复用 CAS 上传器）：证书先经 AliyunCasUploader 上传拿 CertIdentifier
 * （走 RemoteCertStore 去重，store_kind=cas），再调 waf.ModifyDefaultHttps 把证书设为 WAF 实例的
 * **默认 SSL/TLS 证书**。
 *
 * 与 alb/nlb 同：WAF 接口吃**完整 CertIdentifier 字符串**（"{certId}-{region}"）作为 CertId，**不**拆
 * certId+region（对齐 certimate aliyun-waf：`certId := upres.ExtendedData["CertIdentifier"].(string)`
 * 原样塞 ModifyDefaultHttps.CertId）。故 bind 把 remote_cert_id 原样作 certId，无需 ParsesCasCertIdentifier。
 *
 * **简化**：仅实现 certimate WAF3 的「CNAME 接入 + 默认证书」核心路径（deployToWAF3WithCNAME 无 domain 分支）。
 * 不做：cloudresource 云产品接入（DescribeResourceInstanceCerts/ModifyCloudResourceCert）、扩展域名
 * （ModifyDomain，需回灌全部 Listen/Redirect 字段，极复杂）、DescribeDefaultHttps 预读保留原 TLS 配置。
 * TLS 取 certimate 同款默认（tlsv1.2 + EnableTLSv3）——这是 certimate 未拿到 describe 结果前的种子值，安全不降级。
 *
 * region：WAF 服务自身 region（config.region，定 WAF endpoint + ModifyDefaultHttps.RegionId），
 * 与证书的 CAS region（编在 CertIdentifier 里、CAS 全局）相互独立。
 */
class AliyunWafDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig, MatchesAliyunDomains;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'waf';
    }

    public function label(): string
    {
        return '阿里云 WAF';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'instance_id', 'label' => 'WAF 实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'service_version', 'label' => '服务版本', 'type' => 'string', 'required' => false, 'default' => '3.0'],
            ['key' => 'service_type', 'label' => '服务类型', 'type' => 'string', 'required' => false, 'default' => 'cname'],
            ['key' => 'resource_product', 'label' => '云产品类型', 'type' => 'string', 'required' => false],
            ['key' => 'resource_id', 'label' => '云产品资源 ID', 'type' => 'string', 'required' => false],
            ['key' => 'resource_port', 'label' => '云产品资源端口', 'type' => 'number', 'required' => false],
            ['key' => 'domain', 'label' => '扩展域名', 'type' => 'string', 'required' => false],
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
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 CertId）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{instance_id:string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $instanceId = (string) $this->requireConfig($config, 'instance_id');
        $region = (string) $this->requireConfig($config, 'region');
        $version = (string) ($config['service_version'] ?? '3.0');
        if (! in_array($version, ['3', '3.0'], true)) {
            $this->fail("Aliyun WAF 不支持的服务版本: $version");
        }
        $serviceType = strtolower((string) ($config['service_type'] ?? 'cname'));
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $config, $region, $instanceId, $serviceType, $certId) {
            /** @var Wafopenapi $client */
            $client = $this->makeClient('waf', $credentials, $region);
            if ($serviceType === 'cloudresource') {
                $this->bindCloudResource($client, $credentials, $config, $region, $instanceId, $certId);

                return;
            }
            if ($serviceType !== 'cname') {
                $this->fail("Aliyun WAF 不支持的服务类型: $serviceType");
            }
            $domain = (string) ($config['domain'] ?? '');
            if ($domain !== '') {
                $this->bindCnameDomain($client, $region, $instanceId, $domain, $certId);

                return;
            }
            $resourceGroupId = (string) ($credentials['resource_group_id'] ?? '');
            $response = $client->describeDefaultHttps(new DescribeDefaultHttpsRequest(array_filter([
                'regionId' => $region,
                'instanceId' => $instanceId,
                'resourceManagerResourceGroupId' => $resourceGroupId,
            ], static fn (string $value): bool => $value !== '')));
            $default = $response->body?->defaultHttps;
            $client->modifyDefaultHttps(new ModifyDefaultHttpsRequest(array_filter([
                'regionId' => $region,
                'instanceId' => $instanceId,
                'resourceManagerResourceGroupId' => $resourceGroupId,
                'certId' => $certId,
                'TLSVersion' => $default?->TLSVersion ?? 'tlsv1.2',
                // 保持 Certimate d418b5a 行为：仅 TLSVersion 正常回填；EnableTLSv3 非空时仍采用种子值 true。
                'enableTLSv3' => $default?->enableTLSv3 === null ? null : true,
            ], static fn ($value): bool => $value !== '')));
        });
    }

    private function bindCloudResource(Wafopenapi $client, array $credentials, array $config, string $region, string $instanceId, string $certId): void
    {
        $resourceProduct = (string) $this->requireConfig($config, 'resource_product');
        $resourceId = (string) $this->requireConfig($config, 'resource_id');
        $resourcePort = (int) $this->requireConfig($config, 'resource_port');
        $domain = (string) ($config['domain'] ?? '');
        $resourceGroupId = (string) ($credentials['resource_group_id'] ?? '');
        $common = array_filter([
            'regionId' => $region, 'instanceId' => $instanceId,
            'resourceManagerResourceGroupId' => $resourceGroupId,
            'resourceInstanceId' => $resourceId,
        ], static fn (string $value): bool => $value !== '');
        $available = $client->describeResourceInstanceCerts(new DescribeResourceInstanceCertsRequest($common))->body?->certs ?? [];
        $details = $client->describeCloudResourceAccessPortDetails(new DescribeCloudResourceAccessPortDetailsRequest($common + [
            'port' => (string) $resourcePort,
        ]))->body?->accessPortDetails ?? [];
        if (! is_array($details) || $details === []) {
            $this->fail('未找到 WAF 云产品接入端口详情');
        }
        $access = $details[0];
        $current = is_array($access->certificates ?? null) ? $access->certificates : [];
        if ($current === []) {
            $this->fail('未找到 WAF 云产品接入证书');
        }
        foreach ($current as $item) {
            $expectedType = $domain === '' ? 'default' : 'extension';
            if ((string) ($item->appliedType ?? '') === $expectedType && (string) ($item->certificateId ?? '') === $certId) {
                return;
            }
        }
        $available = is_array($available) ? $available : [];
        $certificates = [];
        foreach ($current as $item) {
            $id = (string) ($item->certificateId ?? '');
            $type = (string) ($item->appliedType ?? '');
            $availableCert = $this->findResourceCertificate($available, $id);
            if (($domain === '' && $type === 'default') || ($domain !== '' && $type === 'extension' && (($availableCert->commonName ?? '') === $domain))) {
                continue;
            }
            if ($availableCert === null || (int) ($availableCert->afterDate ?? 0) <= time() * 1000) {
                continue;
            }
            $certificates[] = new cloudCertificates(['certificateId' => $id, 'appliedType' => $type]);
        }
        $certificates[] = new cloudCertificates([
            'certificateId' => $certId,
            'appliedType' => $domain === '' ? 'default' : 'extension',
        ]);
        $client->modifyCloudResourceCert(new ModifyCloudResourceCertRequest([
            'regionId' => $region,
            'instanceId' => $instanceId,
            'cloudResourceId' => (string) ($access->cloudResourceId ?? ''),
            'certificates' => $certificates,
        ]));
    }

    private function findResourceCertificate(array $available, string $certificateId): ?object
    {
        $bareId = explode('-', $certificateId, 2)[0];
        foreach ($available as $item) {
            $candidate = (string) ($item->certIdentifier ?? '');
            if ($candidate === $certificateId || explode('-', $candidate, 2)[0] === $bareId) {
                return $item;
            }
        }

        return null;
    }

    private function bindCnameDomain(Wafopenapi $client, string $region, string $instanceId, string $domain, string $certId): void
    {
        $detail = $client->describeDomainDetail(new DescribeDomainDetailRequest([
            'regionId' => $region, 'instanceId' => $instanceId, 'domain' => $domain,
        ]))->body;
        $listen = new modifyListen(['certId' => $certId]);
        $redirect = new modifyRedirect(['loadbalance' => 'iphash']);
        $this->copyModelProperties($detail?->listen, $listen);
        $listen->certId = $certId;
        $this->copyModelProperties($detail?->redirect, $redirect);
        if (is_array($detail?->redirect?->backends ?? null)) {
            $redirect->backends = array_map(static fn ($item): string => (string) ($item->backend ?? ''), $detail->redirect->backends);
        }
        if (is_array($detail?->redirect?->backupBackends ?? null)) {
            $redirect->backupBackends = array_map(static fn ($item): string => (string) ($item->backend ?? ''), $detail->redirect->backupBackends);
        }
        if (is_array($detail?->redirect?->requestHeaders ?? null)) {
            $redirect->requestHeaders = array_map(
                static fn ($item): modifyRequestHeaders => new modifyRequestHeaders([
                    'key' => (string) ($item->key ?? ''),
                    'value' => (string) ($item->value ?? ''),
                ]),
                $detail->redirect->requestHeaders,
            );
        }
        $client->modifyDomain(new ModifyDomainRequest([
            'regionId' => $region, 'instanceId' => $instanceId, 'domain' => $domain,
            'domainId' => (string) ($detail?->domainId ?? ''),
            'listen' => $listen, 'redirect' => $redirect,
        ]));
    }

    private function copyModelProperties(?object $source, object $target): void
    {
        if ($source === null) {
            return;
        }
        foreach (get_object_vars($target) as $property => $_) {
            if (property_exists($source, $property) && $source->{$property} !== null && ! in_array($property, ['backends', 'backupBackends', 'requestHeaders'], true)) {
                $target->{$property} = $source->{$property};
            }
        }
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            // 接入点：wafopenapi.{region}.aliyuncs.com（空 region 回落 cn-hangzhou）
            'waf' => new Wafopenapi($this->aliyunConfig($credentials, $region !== '' ? "wafopenapi.$region.aliyuncs.com" : 'wafopenapi.cn-hangzhou.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
