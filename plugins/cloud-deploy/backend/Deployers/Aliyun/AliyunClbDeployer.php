<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

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
 * 简化：仅实现「指定 listener_port 关联证书」核心路径（对应 certimate aliyun-clb DEPLOY_TARGET_LISTENER
 * 且无 SNI）；不遍历负载均衡所有 HTTPS 监听、不做 SNI 扩展域名（DescribeDomainExtensions/
 * SetDomainExtensionAttribute）。对齐 certimate updateListenerCertificate 无 SNI 分支：Set 调用只传
 * LoadBalancerId+ListenerPort+ServerCertificateId+RegionId（其余监听属性不传即保留，区别于 WAF ModifyDomain）。
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
            ['key' => 'load_balancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_port', 'label' => '监听端口', 'type' => 'number', 'required' => true],
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
     * @param  array{load_balancer_id:string,listener_port:int|string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $loadBalancerId = (string) $this->requireConfig($config, 'load_balancer_id');
        $listenerPort = (int) $this->requireConfig($config, 'listener_port');
        $region = (string) $this->requireConfig($config, 'region');
        $serverCertificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $loadBalancerId, $listenerPort, $serverCertificateId) {
            /** @var Slb $client */
            $client = $this->makeClient('slb', $credentials, $region);
            $client->setLoadBalancerHTTPSListenerAttribute(new SetLoadBalancerHTTPSListenerAttributeRequest([
                'regionId' => $region,
                'loadBalancerId' => $loadBalancerId,
                'listenerPort' => $listenerPort,
                'serverCertificateId' => $serverCertificateId,
            ]));
        });
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
