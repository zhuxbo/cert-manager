<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeDomainExtensionsRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeLoadBalancerAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeLoadBalancerHTTPSListenerAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\DescribeLoadBalancerListenersRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\SetDomainExtensionAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Models\SetLoadBalancerHTTPSListenerAttributeRequest;
use AlibabaCloud\SDK\Slb\V20140515\Slb;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云传统型负载均衡 CLB（证书服务型，第二类上传器 SLB 服务证书）：
 * 证书先经 AliyunSlbUploader 上传拿 region 维度的 ServerCertificateId（走 RemoteCertStore 去重，
 * store_kind="slb:{region}" 隔离），再调 slb.SetLoadBalancerHTTPSListenerAttribute 把证书绑到指定
 * HTTPS 监听端口。
 *
 * **与 dcdn/alb/nlb 等 CAS 系的本质差异**：CLB 用 SLB 传统服务证书（region 维度），不是 CAS 全局 CertId。
 * ServerCertificateId 只在上传 region 有效，故上传与绑定必须用**同一** region —— certUploader($config) 与
 * bind 都从 config.region 取，保证一致；store_kind 含 region，跨 region 部署各自上传不误复用。
 *
 * 对齐 Certimate：既可更新指定 HTTPS 监听端口，也可遍历负载均衡全部 HTTPS 监听；配置 domain 时
 * 更新精确匹配的 SNI 扩展域名，否则更新监听器默认证书。
 */
class AliyunClbDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'clb';
    }

    public function label(): string
    {
        return '阿里云传统型负载均衡 CLB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => false, 'default' => 'listener'],
            ['key' => 'load_balancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_port', 'label' => '监听端口', 'type' => 'number', 'required' => false],
            ['key' => 'domain', 'label' => 'SNI 域名', 'type' => 'string', 'required' => false],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    /**
     * SLB 服务证书是 region 维度：上传器需在上传前知道 region（决定 endpoint + RegionId + store_kind 隔离）。
     * 故从 config.region 注入；缺 region 时退化为空串（仅元信息探测场景），真实上传路径由 Job 透传 config 保证有值。
     *
     * @param  array<string,mixed>  $config
     */
    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = (string) ($config['region'] ?? '');

        // 上传器复用 deployer 的注入缝：测试 override makeClient('slb') 即作用于上传
        return new AliyunSlbUploader(
            fn (array $credentials): object => $this->makeClient('slb', $credentials, $region),
            $region,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（裸 ServerCertificateId，region 已编进 store_kind 无需拆）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{load_balancer_id:string,listener_port?:int|string,region:string,deploy_target?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $loadBalancerId = (string) $this->requireConfig($config, 'load_balancer_id');
        $region = (string) $this->requireConfig($config, 'region');
        $target = strtolower((string) ($config['deploy_target'] ?? 'listener'));
        $listenerPort = (int) ($config['listener_port'] ?? 0);
        $domain = (string) ($config['domain'] ?? '');
        if ($target === 'listener' && $listenerPort === 0) {
            $this->requireConfig($config, 'listener_port');
        }
        if (! in_array($target, ['listener', 'loadbalancer'], true)) {
            $this->fail("Aliyun CLB 不支持的部署目标: $target");
        }
        $serverCertificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $target, $loadBalancerId, $listenerPort, $domain, $serverCertificateId) {
            /** @var Slb $client */
            $client = $this->makeClient('slb', $credentials, $region);
            $listenerPorts = $target === 'loadbalancer'
                ? $this->findLoadBalancerListeners($client, $region, $loadBalancerId)
                : [$listenerPort];
            foreach ($listenerPorts as $matchedListenerPort) {
                $this->updateListenerCertificate(
                    $client,
                    $region,
                    $loadBalancerId,
                    $matchedListenerPort,
                    $domain,
                    $serverCertificateId,
                );
            }
        });
    }

    /** @return list<int> */
    private function findLoadBalancerListeners(Slb $client, string $region, string $loadBalancerId): array
    {
        $client->describeLoadBalancerAttribute(new DescribeLoadBalancerAttributeRequest([
            'regionId' => $region,
            'loadBalancerId' => $loadBalancerId,
        ]));

        $listenerPorts = [];
        $nextToken = null;
        do {
            $response = $client->describeLoadBalancerListeners(new DescribeLoadBalancerListenersRequest([
                'regionId' => $region,
                'nextToken' => $nextToken,
                'maxResults' => 100,
                'loadBalancerId' => [$loadBalancerId],
                'listenerProtocol' => 'https',
            ]));
            $listeners = is_array($response->body?->listeners ?? null) ? $response->body->listeners : [];
            foreach ($listeners as $listener) {
                $port = (int) ($listener->listenerPort ?? 0);
                if ($port > 0) {
                    $listenerPorts[] = $port;
                }
            }
            $nextToken = $response->body->nextToken ?? null;
        } while ($listeners !== [] && is_string($nextToken) && $nextToken !== '');

        return $listenerPorts;
    }

    private function updateListenerCertificate(
        Slb $client,
        string $region,
        string $loadBalancerId,
        int $listenerPort,
        string $domain,
        string $serverCertificateId,
    ): void {
        $response = $client->describeLoadBalancerHTTPSListenerAttribute(new DescribeLoadBalancerHTTPSListenerAttributeRequest([
            'regionId' => $region,
            'loadBalancerId' => $loadBalancerId,
            'listenerPort' => $listenerPort,
        ]));

        if ($domain === '') {
            if ((string) ($response->body?->serverCertificateId ?? '') === $serverCertificateId) {
                return;
            }
            $client->setLoadBalancerHTTPSListenerAttribute(new SetLoadBalancerHTTPSListenerAttributeRequest([
                'regionId' => $region,
                'loadBalancerId' => $loadBalancerId,
                'listenerPort' => $listenerPort,
                'serverCertificateId' => $serverCertificateId,
            ]));

            return;
        }

        $extensionsResponse = $client->describeDomainExtensions(new DescribeDomainExtensionsRequest([
            'regionId' => $region,
            'loadBalancerId' => $loadBalancerId,
            'listenerPort' => $listenerPort,
        ]));
        $extensions = $extensionsResponse->body?->domainExtensions?->domainExtension ?? [];
        foreach (is_array($extensions) ? $extensions : [] as $extension) {
            if ((string) ($extension->domain ?? '') !== $domain
                || (string) ($extension->serverCertificateId ?? '') === $serverCertificateId) {
                continue;
            }
            $client->setDomainExtensionAttribute(new SetDomainExtensionAttributeRequest([
                'regionId' => $region,
                'domainExtensionId' => (string) ($extension->domainExtensionId ?? ''),
                'serverCertificateId' => $serverCertificateId,
            ]));
        }
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {

        return match ($kind) {
            // 接入点：region 化（空 region 回落中心 endpoint slb.aliyuncs.com，等价 cn-hangzhou）
            'slb' => new Slb($this->aliyunConfig($credentials, $region !== '' ? "slb.$region.aliyuncs.com" : 'slb.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
