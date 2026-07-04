<?php

namespace Plugins\CloudDeploy\Deployers\Cmcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 移动云弹性负载均衡 VLB（证书服务型，资源池维度）：证书先经 VLB 证书服务上传拿 certId（走 RemoteCertStore 去重），
 * 再设到负载均衡 HTTPS 监听器（默认证书或 SNI 证书）。
 *
 * 对齐 certimate cmcccloud-vlb：
 *   1. 证书经 CmcccloudVlbUploader 上传（CreateLoadbalanceCertification，type 由是否指定 SNI 域名决定）拿 certId。
 *   2. 按部署目标绑定（均需 loadbalancer_id 作为 ListLoadBalanceHTTPSListener 的路径参数）：
 *      - DEPLOY_TARGET_LOADBALANCER：分页拉负载均衡器全部 HTTPS 监听器，逐个 updateListener。
 *      - DEPLOY_TARGET_LISTENER：直接对配置的监听器 updateListener。
 *   3. updateListener（PUT /acl/v3/listener）：
 *      - 未指定 SNI 域名：{id, defaultTlsContainerId: certId}（已是该默认证书则跳过）。
 *      - 指定 SNI 域名：{id, sniUp:true, sniContainerIds: 追加 certId}（已含则跳过）。
 *
 * 与 certimate 对齐的取舍：domain（SNI 域名）选填——为空走默认证书、非空走 SNI 证书（与 certimate IsSNI 逻辑一致，
 * 并据此决定上传的证书 type，编入 storeKind 隔离）。轮询/异步任务结果（certimate vlb 同步返回）不涉及。
 */
class CmcccloudVlbDeployer extends AbstractDeployer
{
    private const PAGE_SIZE = 10;

    private const GW_LIST_HTTPS_LISTENER = '/api/openapi-vlb/lb-console/protocol/v3/listener/{loadBalanceId}/listeners/https';

    private const GW_UPDATE_LISTENER = '/api/openapi-vlb/lb-console/acl/v3/listener';

    public function provider(): string
    {
        return 'cmcccloud';
    }

    public function product(): string
    {
        return 'vlb';
    }

    public function label(): string
    {
        return '移动云 VLB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'pool_id', 'label' => '资源池 ID', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '负载均衡器（全部 HTTPS 监听）', 'value' => 'loadbalancer'],
                ['label' => '指定监听器', 'value' => 'listener'],
            ]],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡器 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听器 ID（部署目标为指定监听器时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => 'SNI 域名（选填，填则部署到 SNI 证书）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $poolId = isset($config['pool_id']) ? (string) $config['pool_id'] : '';
        $isSni = isset($config['domain']) && (string) $config['domain'] !== '';

        return new CmcccloudVlbUploader(
            fn (array $credentials): object => $this->makeClient('vlb', $credentials, $poolId),
            $poolId,
            $isSni,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（VLB certId）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{pool_id:string,deploy_target:string,loadbalancer_id:string,listener_id?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $poolId = (string) $this->requireConfig($config, 'pool_id');
        $deployTarget = (string) $this->requireConfig($config, 'deploy_target');
        $loadbalancerId = (string) $this->requireConfig($config, 'loadbalancer_id');
        $domain = isset($config['domain']) ? (string) $config['domain'] : '';
        $certId = (string) $certRef;

        $listenerIds = match ($deployTarget) {
            'loadbalancer' => null, // 枚举全部 HTTPS 监听（在 guardSdk 内做）
            'listener' => [(string) $this->requireConfig($config, 'listener_id')],
            default => $this->fail("不支持的部署目标: $deployTarget"),
        };

        $this->guardSdk(function () use ($credentials, $poolId, $loadbalancerId, $listenerIds, $certId, $domain) {
            /** @var CmcccloudRestClient $client */
            $client = $this->makeClient('vlb', $credentials, $poolId);

            $targets = $listenerIds ?? $this->listHttpsListenerIds($client, $loadbalancerId);
            if ($targets === []) {
                throw new CmcccloudApiException('ListenerNotFound', "负载均衡器 $loadbalancerId 下未找到 HTTPS 监听器");
            }

            foreach ($targets as $listenerId) {
                $this->updateListenerCertificate($client, $loadbalancerId, $listenerId, $certId, $domain);
            }
        });
    }

    /**
     * 分页拉负载均衡器全部 HTTPS 监听器 ID。
     *
     * @return list<string>
     */
    private function listHttpsListenerIds(CmcccloudRestClient $client, string $loadbalancerId): array
    {
        $ids = [];
        $page = 1;

        while (true) {
            $content = $this->fetchHttpsListenerPage($client, $loadbalancerId, $page);
            foreach ($content as $listener) {
                $id = is_string($listener['id'] ?? null) ? $listener['id'] : '';
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            if (count($content) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $ids;
    }

    /**
     * 更新单个监听器证书：未指定 SNI → 设默认证书 defaultTlsContainerId；指定 SNI → 追加 sniContainerIds。
     * 已是目标状态则跳过（对齐 certimate updateListenerCertificate）。
     */
    private function updateListenerCertificate(CmcccloudRestClient $client, string $loadbalancerId, string $listenerId, string $certId, string $domain): void
    {
        $listenerInfo = $this->findListener($client, $loadbalancerId, $listenerId);
        if ($listenerInfo === null) {
            throw new CmcccloudApiException('ListenerNotFound', "未找到监听器: $listenerId");
        }

        if ($domain === '') {
            // 未指定 SNI：部署到默认证书
            if (($listenerInfo['defaultTlsContainerId'] ?? null) === $certId) {
                return;
            }
            $client->call('PUT', self::GW_UPDATE_LISTENER, [], [], [
                'id' => $listenerId,
                'defaultTlsContainerId' => $certId,
            ]);

            return;
        }

        // 指定 SNI：追加到 SNI 证书列表
        $sniList = is_array($listenerInfo['sniContainerIdList'] ?? null) ? $listenerInfo['sniContainerIdList'] : [];
        if (in_array($certId, $sniList, true)) {
            return;
        }
        $sniList[] = $certId;
        $client->call('PUT', self::GW_UPDATE_LISTENER, [], [], [
            'id' => $listenerId,
            'sniUp' => true,
            'sniContainerIds' => array_values($sniList),
        ]);
    }

    /**
     * 分页查找指定监听器的完整信息（含 defaultTlsContainerId / sniContainerIdList）。
     *
     * @return array<string,mixed>|null
     */
    private function findListener(CmcccloudRestClient $client, string $loadbalancerId, string $listenerId): ?array
    {
        $page = 1;
        while (true) {
            $content = $this->fetchHttpsListenerPage($client, $loadbalancerId, $page);
            foreach ($content as $listener) {
                if (($listener['id'] ?? null) === $listenerId) {
                    return $listener;
                }
            }

            if (count($content) < self::PAGE_SIZE) {
                return null;
            }
            $page++;
        }
    }

    /**
     * 拉取一页 HTTPS 监听器列表（ListLoadBalanceHTTPSListener，loadBalanceId 路径参 + page/pageSize 查询）。
     *
     * @return list<array<string,mixed>>
     */
    private function fetchHttpsListenerPage(CmcccloudRestClient $client, string $loadbalancerId, int $page): array
    {
        $resp = $client->call('GET', self::GW_LIST_HTTPS_LISTENER, ['loadBalanceId' => $loadbalancerId], [
            'page' => (string) $page,
            'pageSize' => (string) self::PAGE_SIZE,
        ]);
        $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
        $content = is_array($body['content'] ?? null) ? $body['content'] : [];

        return array_values(array_filter($content, 'is_array'));
    }

    protected function makeClient(string $kind, array $credentials, string $poolId = ''): object
    {
        return match ($kind) {
            'vlb' => new CmcccloudRestClient(
                $credentials['access_key_id'] ?? '',
                $credentials['access_key_secret'] ?? '',
                $poolId,
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CmcccloudErrorSanitizer::sanitize($e);
    }
}
