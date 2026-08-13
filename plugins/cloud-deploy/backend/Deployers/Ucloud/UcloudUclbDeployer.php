<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 优刻得 传统型负载均衡（ULB - CLB）部署器 —— 证书服务型（usesRemoteCertStore=true，ULB 证书空间）。
 *
 * 流程对齐 certimate ucloud-uclb：
 *   1. 证书经 ULB CreateSSL 上传拿 SSLId（区域型，走 RemoteCertStore 按 region 去重）。
 *   2. 按 deploy_target 绑定：
 *      - loadbalancer：DescribeVServer 列出该 ULB 下所有 HTTPS VServer，逐个绑证书。
 *      - vserver：仅绑指定 VServer。
 *   3. VServer 绑定（updateVServerCertificate）：CLB 每个 VServer **最多绑一个证书**，故须先 UnbindSSL
 *      解绑旧证书，再 BindSSL 绑新证书（certimate #1224）。已绑同证书则跳过。
 */
class UcloudUclbDeployer extends AbstractDeployer
{
    use UcloudClientFactory;

    /** 部署目标：整个负载均衡器（所有 HTTPS VServer）。 */
    private const TARGET_LOADBALANCER = 'loadbalancer';

    /** 部署目标：指定 VServer。 */
    private const TARGET_VSERVER = 'vserver';

    public function provider(): string
    {
        return 'ucloud';
    }

    public function product(): string
    {
        return 'uclb';
    }

    public function label(): string
    {
        return '优刻得 传统型负载均衡 ULB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'vserver_id', 'label' => 'VServer ID（部署目标为 VServer 时必填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = is_string($config['region'] ?? null) ? $config['region'] : '';

        return new UcloudUlbUploader(
            fn (array $credentials): object => $this->makeClient('api', $this->withUcloudEndpoint($credentials, $config), $region),
            $region,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id = ULB SSLId
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @param  array{region:string,deploy_target:string,loadbalancer_id:string,vserver_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withUcloudEndpoint($credentials, $config);
        $region = (string) $this->requireConfig($config, 'region');
        $target = (string) $this->requireConfig($config, 'deploy_target');
        $loadbalancerId = (string) $this->requireConfig($config, 'loadbalancer_id');
        $sslId = (string) $certRef;

        if ($target === self::TARGET_VSERVER) {
            $vserverId = (string) $this->requireConfig($config, 'vserver_id');
            $this->updateVServerCertificate($credentials, $region, $loadbalancerId, $vserverId, $sslId);

            return;
        }

        if ($target === self::TARGET_LOADBALANCER) {
            $this->deployToLoadbalancer($credentials, $region, $loadbalancerId, $sslId);

            return;
        }

        $this->fail("不支持的部署目标 $target");
    }

    /**
     * 列出 ULB 下所有 HTTPS VServer 并逐个绑证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToLoadbalancer(array $credentials, string $region, string $loadbalancerId, string $sslId): void
    {
        $vserverIds = [];
        $offset = 0;
        $limit = 100;
        do {
            $resp = $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
                ->describeVServer($loadbalancerId, null, $offset, $limit));
            $vservers = $resp['DataSet'];
            foreach ($vservers as $vserver) {
                if (($vserver['Protocol'] ?? null) === 'HTTPS' && is_string($vserver['VServerId'] ?? null)) {
                    $vserverIds[] = $vserver['VServerId'];
                }
            }
            $offset += $limit;
        } while (count($vservers) >= $limit);

        foreach ($vserverIds as $vserverId) {
            $this->updateVServerCertificate($credentials, $region, $loadbalancerId, $vserverId, $sslId);
        }
    }

    /**
     * 给单个 VServer 绑证书：CLB 单证书，先解绑已有再绑新。已绑同证书则跳过。
     * SDK 调用各自包 guardSdk，业务判断（fail/跳过）放其外。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function updateVServerCertificate(array $credentials, string $region, string $loadbalancerId, string $vserverId, string $sslId): void
    {
        $resp = $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
            ->describeVServer($loadbalancerId, $vserverId, 0, 1));
        if ($resp['DataSet'] === []) {
            $this->fail("优刻得 ULB 未找到 VServer $vserverId");
        }
        $vserver = $resp['DataSet'][0];
        $sslSet = is_array($vserver['SSLSet'] ?? null) ? $vserver['SSLSet'] : [];

        // 已绑同证书则跳过
        foreach ($sslSet as $ssl) {
            if (is_array($ssl) && ($ssl['SSLId'] ?? null) === $sslId) {
                return;
            }
        }

        // CLB 每 VServer 最多一个证书：先解绑旧的再绑新的
        foreach ($sslSet as $ssl) {
            if (is_array($ssl) && is_string($ssl['SSLId'] ?? null) && $ssl['SSLId'] !== '') {
                $oldSslId = $ssl['SSLId'];
                $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
                    ->unbindSSL($loadbalancerId, $vserverId, $oldSslId));
            }
        }

        $this->guardSdk(fn () => $this->makeClient('api', $credentials, $region)
            ->bindSSL($loadbalancerId, $vserverId, $sslId));
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
