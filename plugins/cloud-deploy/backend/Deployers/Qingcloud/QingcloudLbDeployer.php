<?php

namespace Plugins\CloudDeploy\Deployers\Qingcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 青云负载均衡 LB（证书服务型，zone 维度）：证书先经 IaaS 上传拿 server_certificate_id（走 RemoteCertStore 去重），
 * 再绑定到 LB 的 HTTPS 监听器。
 *
 * 对齐 certimate qingcloud-lb：
 *   1. 证书经 QingcloudLbUploader 上传（CreateServerCertificate）拿 server_certificate_id（store_kind=qingcloud:{zone}）。
 *   2. 按部署目标绑定：
 *      - DEPLOY_TARGET_LOADBALANCER：DescribeLoadBalancerListeners 分页拉负载均衡器全部监听器，过滤 https
 *        协议监听器，逐个 AssociateServerCertsToLBListener。
 *      - DEPLOY_TARGET_LISTENER：直接对配置的监听器 AssociateServerCertsToLBListener。
 *
 * 绑定接口（对齐 certimate updateListenerCertificate）：
 *   POST AssociateServerCertsToLBListener {loadbalancer_listener, server_certificates.1=certId}
 *
 * 与 certimate 对齐的取舍：certimate 先上传证书再枚举监听；本插件证书上传由 RemoteCertStore 统一完成（cert-service
 * 型），bind 收到 server_certificate_id 字符串后枚举/绑定。仅按 https 协议过滤监听器（与 certimate 一致）。
 */
class QingcloudLbDeployer extends AbstractDeployer
{
    private const PAGE_SIZE = 100;

    public function provider(): string
    {
        return 'qingcloud';
    }

    public function product(): string
    {
        return 'lb';
    }

    public function label(): string
    {
        return '青云 LB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'zone', 'label' => '区域 ID', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '负载均衡器（全部 HTTPS 监听）', 'value' => 'loadbalancer'],
                ['label' => '指定监听器', 'value' => 'listener'],
            ]],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡器 ID（部署目标为负载均衡器时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'listener_id', 'label' => '监听器 ID（部署目标为指定监听器时必填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 青云服务器证书 zone 维度：据 config.zone 构造上传器（编入 storeKind 隔离跨 zone 标识空间）。
        $zone = isset($config['zone']) ? (string) $config['zone'] : '';

        return new QingcloudLbUploader(
            fn (array $credentials): object => $this->makeClient('lb', $credentials, $zone),
            $zone,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（青云 server_certificate_id）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{zone:string,deploy_target:string,loadbalancer_id?:string,listener_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $zone = (string) $this->requireConfig($config, 'zone');
        $deployTarget = (string) $this->requireConfig($config, 'deploy_target');
        $certId = (string) $certRef;

        $listenerIds = match ($deployTarget) {
            'loadbalancer' => $this->resolveLoadbalancerListeners($config, $credentials, $zone),
            'listener' => [(string) $this->requireConfig($config, 'listener_id')],
            default => $this->fail("不支持的部署目标: $deployTarget"),
        };

        $this->guardSdk(function () use ($credentials, $zone, $listenerIds, $certId) {
            /** @var QingcloudRestClient $client */
            $client = $this->makeClient('lb', $credentials, $zone);
            foreach ($listenerIds as $listenerId) {
                $client->post('AssociateServerCertsToLBListener', [
                    'loadbalancer_listener' => $listenerId,
                    'server_certificates' => [$certId],
                ]);
            }
        });
    }

    /**
     * 分页拉负载均衡器全部监听器，过滤出 https 协议监听器 ID 列表。
     *
     * @param  array{loadbalancer_id?:string}  $config
     * @param  array<string,mixed>  $credentials
     * @return list<string>
     */
    private function resolveLoadbalancerListeners(array $config, array $credentials, string $zone): array
    {
        $loadbalancerId = (string) $this->requireConfig($config, 'loadbalancer_id');

        return $this->guardSdk(function () use ($credentials, $zone, $loadbalancerId): array {
            /** @var QingcloudRestClient $client */
            $client = $this->makeClient('lb', $credentials, $zone);

            $listenerIds = [];
            $offset = 0;
            while (true) {
                $resp = $client->get('DescribeLoadBalancerListeners', [
                    'loadbalancer' => $loadbalancerId,
                    'offset' => $offset,
                    'limit' => self::PAGE_SIZE,
                ]);
                $set = is_array($resp['loadbalancer_listener_set'] ?? null) ? $resp['loadbalancer_listener_set'] : [];

                foreach ($set as $listener) {
                    if (! is_array($listener)) {
                        continue;
                    }
                    $protocol = is_string($listener['listener_protocol'] ?? null) ? $listener['listener_protocol'] : '';
                    if (strcasecmp($protocol, 'https') !== 0) {
                        continue;
                    }
                    $id = isset($listener['loadbalancer_listener_id']) ? (string) $listener['loadbalancer_listener_id'] : '';
                    if ($id !== '') {
                        $listenerIds[] = $id;
                    }
                }

                if (count($set) < self::PAGE_SIZE) {
                    break;
                }
                $offset += self::PAGE_SIZE;
            }

            if ($listenerIds === []) {
                throw new QingcloudApiException('ListenerNotFound', "负载均衡器 $loadbalancerId 下未找到 HTTPS 监听器");
            }

            return $listenerIds;
        });
    }

    protected function makeClient(string $kind, array $credentials, string $zone = ''): object
    {
        return match ($kind) {
            'lb' => new QingcloudRestClient(
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                $zone,
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return QingcloudErrorSanitizer::sanitize($e);
    }
}
