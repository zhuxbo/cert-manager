<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 京东云负载均衡 ALB（证书服务型，lb 服务）：证书先经 SSL 证书中心上传拿 certId（走 RemoteCertStore
 * 去重），再按部署目标绑定到负载均衡器或监听器。
 *
 * 对齐 certimate jdcloud-alb 两种部署目标：
 *   - deploy_target=listener：
 *       · 无 SNI（domain 空）：UpdateListener（PATCH .../listeners/{listenerId}，
 *         certificateSpecs=[{certificateId}]）改默认证书。
 *       · 有 SNI（domain）：DescribeListener 取 extensionCertificateSpecs，过滤 domain 命中的扩展证书 →
 *         UpdateListenerCertificates（POST .../listeners/{listenerId}:updateListenerCertificates，
 *         certificates=[{certificateBindId, certificateId, domain}]）。
 *   - deploy_target=loadbalancer：
 *       DescribeListeners 分页枚举该 LB 下 https/tls 协议监听器 → 逐个 UpdateListener 改默认证书。
 *
 * 与 certimate 对齐取舍：保留 loadbalancer / listener 两目标 + SNI 扩展证书分支；
 * loadbalancer 路径的 DescribeLoadBalancer 校验略去（certimate 仅用于日志，不影响绑定）。
 */
class JdcloudAlbDeployer extends AbstractDeployer
{
    private const TARGET_LOADBALANCER = 'loadbalancer';

    private const TARGET_LISTENER = 'listener';

    public function provider(): string
    {
        return 'jdcloud';
    }

    public function product(): string
    {
        return 'alb';
    }

    public function label(): string
    {
        return '京东云负载均衡';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region_id', 'label' => '地域 ID', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '负载均衡实例（全部 HTTPS/TLS 监听）', 'value' => self::TARGET_LOADBALANCER],
                ['label' => '指定监听器', 'value' => self::TARGET_LISTENER],
            ]],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡实例 ID（部署目标为负载均衡实例时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'listener_id', 'label' => '监听器 ID（部署目标为指定监听器时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => 'SNI 扩展域名（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new JdcloudSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  云端 certId（SSL 证书中心上传所得）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{region_id:string,deploy_target:string,loadbalancer_id?:string,listener_id?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $regionId = (string) $this->requireConfig($config, 'region_id');
        $target = (string) $this->requireConfig($config, 'deploy_target');
        $certId = (string) $certRef;
        $domain = (string) ($config['domain'] ?? '');

        match ($target) {
            self::TARGET_LISTENER => $this->deployToListener(
                $credentials,
                $regionId,
                (string) $this->requireConfig($config, 'listener_id'),
                $domain,
                $certId,
            ),
            self::TARGET_LOADBALANCER => $this->deployToLoadbalancer(
                $credentials,
                $regionId,
                (string) $this->requireConfig($config, 'loadbalancer_id'),
                $certId,
            ),
            default => $this->fail("不支持的部署目标 $target"),
        };
    }

    /**
     * 部署到指定监听器：无 SNI 改默认证书；有 SNI 改匹配 domain 的扩展证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToListener(array $credentials, string $regionId, string $listenerId, string $domain, string $certId): void
    {
        if ($domain === '') {
            // 无 SNI → 改监听器默认证书
            $this->guardSdk(function () use ($credentials, $regionId, $listenerId, $certId) {
                $this->makeClient('lb', $credentials)->updateAlbListenerCertificate($regionId, $listenerId, $certId);
            });

            return;
        }

        // 有 SNI → 先查扩展证书定位 certificateBindId（SDK 调用包 guardSdk）
        $extSpecs = $this->guardSdk(
            fn (): array => $this->makeClient('lb', $credentials)->describeAlbListener($regionId, $listenerId)['extensionCertificateSpecs'],
        );

        // 过滤 domain 命中（业务逻辑，放 guardSdk 外）
        $matched = [];
        foreach ($extSpecs as $spec) {
            if (is_array($spec) && ($spec['domain'] ?? null) === $domain) {
                $matched[] = [
                    'certificateBindId' => (string) ($spec['certificateBindId'] ?? ''),
                    'certificateId' => $certId,
                    'domain' => $domain,
                ];
            }
        }
        if ($matched === []) {
            $this->fail("监听器 $listenerId 未找到匹配 SNI 域名 $domain 的扩展证书");
        }

        $this->guardSdk(function () use ($credentials, $regionId, $listenerId, $matched) {
            $this->makeClient('lb', $credentials)->updateAlbListenerCertificates($regionId, $listenerId, $matched);
        });
    }

    /**
     * 部署到负载均衡实例：枚举 https/tls 监听器，逐个改默认证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToLoadbalancer(array $credentials, string $regionId, string $loadBalancerId, string $certId): void
    {
        $listenerIds = $this->guardSdk(
            fn (): array => $this->makeClient('lb', $credentials)->listAlbHttpsListenerIds($regionId, $loadBalancerId),
        );

        // 无 https/tls 监听器：与 certimate 一致视为成功无操作（不 fail）
        foreach ($listenerIds as $listenerId) {
            $this->guardSdk(function () use ($credentials, $regionId, $listenerId, $certId) {
                $this->makeClient('lb', $credentials)->updateAlbListenerCertificate($regionId, (string) $listenerId, $certId);
            });
        }
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'ssl' => JdcloudClientFactory::ssl($credentials),
            'lb' => JdcloudClientFactory::lb($credentials),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return JdcloudErrorSanitizer::sanitize($e);
    }
}
