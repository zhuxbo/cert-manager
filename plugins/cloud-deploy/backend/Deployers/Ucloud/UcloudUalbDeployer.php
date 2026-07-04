<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 优刻得 应用型负载均衡（ULB - ALB）部署器 —— 证书服务型（usesRemoteCertStore=true，ULB 证书空间）。
 *
 * 流程对齐 certimate ucloud-ualb：
 *   1. 证书经 ULB CreateSSL 上传拿 SSLId（区域型，走 RemoteCertStore 按 region 去重）。
 *   2. 按 deploy_target 绑定：
 *      - loadbalancer：DescribeListeners 列出该 ULB 下所有 HTTPS 监听器，逐个绑证书。
 *      - listener：仅绑指定监听器。
 *   3. 监听器绑定（updateListenerCertificate）：
 *      - 未指定 SNI domain → UpdateListenerAttribute 设为默认证书。
 *      - 指定 SNI domain → AddSSLBinding 增扩展证书（SNI）。
 *      已绑同证书（默认/扩展按 domain 区分）则跳过。
 *
 * 简化（相对 certimate，已注明）：SNI 路径只新增扩展绑定，**不**做「删除同域名旧扩展证书 / 过期证书」的
 * DescribeSSLV2 清理 sweep（属配额维护、非核心绑定路径；与插件 RemoteCertStore「只增不删」一致）。
 */
class UcloudUalbDeployer extends AbstractDeployer
{
    use UcloudClientFactory;

    /** 部署目标：整个负载均衡器（所有 HTTPS 监听器）。 */
    private const TARGET_LOADBALANCER = 'loadbalancer';

    /** 部署目标：指定监听器。 */
    private const TARGET_LISTENER = 'listener';

    public function provider(): string
    {
        return 'ucloud';
    }

    public function product(): string
    {
        return 'ualb';
    }

    public function label(): string
    {
        return '优刻得 应用型负载均衡 ULB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听器 ID（部署目标为监听器时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => 'SNI 域名（监听器扩展证书，可选）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // ULB 证书空间区域型：按 config.region 构造 region-scoped 上传器（storeKind=ucloud_ulb:{region}）。
        $region = is_string($config['region'] ?? null) ? $config['region'] : '';

        return new UcloudUlbUploader(
            fn (array $credentials): object => $this->makeClient('api', $credentials, $region),
            $region,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id = ULB SSLId
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @param  array{region:string,deploy_target:string,loadbalancer_id:string,listener_id?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $target = (string) $this->requireConfig($config, 'deploy_target');
        $loadbalancerId = (string) $this->requireConfig($config, 'loadbalancer_id');
        $sslId = (string) $certRef;
        $domain = isset($config['domain']) && is_string($config['domain']) ? $config['domain'] : '';

        if ($target === self::TARGET_LISTENER) {
            $listenerId = (string) $this->requireConfig($config, 'listener_id');
            $this->updateListenerCertificate($credentials, $region, $loadbalancerId, $listenerId, $sslId, $domain);

            return;
        }

        if ($target === self::TARGET_LOADBALANCER) {
            $this->deployToLoadbalancer($credentials, $region, $loadbalancerId, $sslId, $domain);

            return;
        }

        $this->fail("不支持的部署目标 $target");
    }

    /**
     * 列出 ULB 下所有 HTTPS 监听器并逐个绑证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToLoadbalancer(array $credentials, string $region, string $loadbalancerId, string $sslId, string $domain): void
    {
        $listenerIds = [];
        $offset = 0;
        $limit = 100;
        do {
            // SDK 调用包 guardSdk，返回结果；筛选/翻页判断放 guardSdk 外。
            $resp = $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
                ->describeListeners($loadbalancerId, null, $offset, $limit));
            $listeners = $resp['Listeners'];
            foreach ($listeners as $listener) {
                if (($listener['ListenerProtocol'] ?? null) === 'HTTPS' && is_string($listener['ListenerId'] ?? null)) {
                    $listenerIds[] = $listener['ListenerId'];
                }
            }
            $offset += $limit;
        } while (count($listeners) >= $limit);

        foreach ($listenerIds as $listenerId) {
            $this->updateListenerCertificate($credentials, $region, $loadbalancerId, $listenerId, $sslId, $domain);
        }
    }

    /**
     * 给单个监听器绑证书：无 SNI → UpdateListenerAttribute（默认证书）；有 SNI → AddSSLBinding（扩展证书）。
     * 已绑同证书（默认/扩展按 isDefault 区分）则跳过。SDK 调用各自包 guardSdk，业务判断（fail/跳过）放其外。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function updateListenerCertificate(array $credentials, string $region, string $loadbalancerId, string $listenerId, string $sslId, string $domain): void
    {
        $resp = $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
            ->describeListeners($loadbalancerId, $listenerId, 0, 1));
        if ($resp['Listeners'] === []) {
            $this->fail("优刻得 ULB 未找到监听器 $listenerId");
        }
        $listener = $resp['Listeners'][0];
        $certificates = is_array($listener['Certificates'] ?? null) ? $listener['Certificates'] : [];

        if ($domain === '') {
            // 已作为默认证书绑定则跳过
            foreach ($certificates as $cert) {
                if (is_array($cert) && ($cert['SSLId'] ?? null) === $sslId && ($cert['IsDefault'] ?? false)) {
                    return;
                }
            }
            $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
                ->updateListenerAttribute($loadbalancerId, $listenerId, [$sslId]));

            return;
        }

        // SNI：已作为扩展证书绑定则跳过
        foreach ($certificates as $cert) {
            if (is_array($cert) && ($cert['SSLId'] ?? null) === $sslId && ! ($cert['IsDefault'] ?? false)) {
                return;
            }
        }
        $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
            ->addSSLBinding($loadbalancerId, $listenerId, [$sslId]));
    }

    /**
     * 区域型注入缝：region 取自 config。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'api' => $this->buildUcloudClient($credentials, $region),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return UcloudErrorSanitizer::sanitize($e);
    }
}
