<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 天翼云弹性负载均衡 ELB（证书服务型，region 维度，绑定负载均衡监听器）。
 *
 * 对齐 certimate ctcccloud-elb 的 Deploy：
 *   1. 创建证书到 ELB 证书空间拿 CertId（经 CtcccloudElbUploader，store_kind=ctcccloud_elb:{region}，
 *      POST /v4/elb/create-certificate，走 RemoteCertStore 去重）。
 *   2. bind 按 deploy_target 分流：
 *      - loadbalancer：GET /v4/elb/list-listener?regionID&loadBalancerID 列监听器 → 过滤 protocol=HTTPS →
 *        逐个 POST /v4/elb/update-listener {regionID, listenerID, certificateID=CertId} 更新证书。
 *      - listener：直接对 config.listener_id POST /v4/elb/update-listener。
 *
 * region 维度：ELB 证书与资源隶属资源池（regionID），上传与绑定必须同 region —— certUploader($config) 与 bind 都
 * 从 config.region_id 取，store_kind 含 region 隔离去重（与 AliyunClbDeployer 同模式）。
 *
 * 配置：region_id + deploy_target 必填；loadbalancer_id（target=loadbalancer 时）/ listener_id（target=listener 时）
 * 按 deploy_target 条件必填（schema 标 optional、代码内 fail() 强校验，对齐 certimate 的条件必填）。
 */
class CtcccloudElbDeployer extends AbstractDeployer
{
    private const DEPLOY_TARGET_LOADBALANCER = 'loadbalancer';

    private const DEPLOY_TARGET_LISTENER = 'listener';

    public function provider(): string
    {
        return 'ctcccloud';
    }

    public function product(): string
    {
        return 'elb';
    }

    public function label(): string
    {
        return '天翼云弹性负载均衡 ELB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region_id', 'label' => '资源池 ID', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标（loadbalancer/listener）', 'type' => 'string', 'required' => true],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡实例 ID（部署目标为 loadbalancer 时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'listener_id', 'label' => '监听器 ID（部署目标为 listener 时必填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    /**
     * ELB 证书是 region 维度：上传器需在上传前知道 region（决定 RegionID + store_kind 隔离）。
     * 从 config.region_id 注入；缺时退化为空串（仅元信息探测场景），真实上传路径由 Job 透传 config 保证有值。
     *
     * @param  array<string,mixed>  $config
     */
    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = (string) ($config['region_id'] ?? '');

        return new CtcccloudElbUploader(
            fn (array $credentials): object => $this->makeClient('elb', $credentials),
            $region,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（ELB CertId）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{region_id:string,deploy_target:string,loadbalancer_id?:string,listener_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $regionId = (string) $this->requireConfig($config, 'region_id');
        $deployTarget = (string) $this->requireConfig($config, 'deploy_target');
        $certId = (string) $certRef;

        // 业务校验（条件必填 + 目标合法性）在 guardSdk 外 —— 否则业务异常会被当 SDK 异常脱敏，丢失可读文案。
        $loadbalancerId = '';
        $listenerId = '';
        switch ($deployTarget) {
            case self::DEPLOY_TARGET_LOADBALANCER:
                $loadbalancerId = (string) ($config['loadbalancer_id'] ?? '');
                if ($loadbalancerId === '') {
                    $this->fail('缺少配置 loadbalancer_id');
                }
                break;

            case self::DEPLOY_TARGET_LISTENER:
                $listenerId = (string) ($config['listener_id'] ?? '');
                if ($listenerId === '') {
                    $this->fail('缺少配置 listener_id');
                }
                break;

            default:
                $this->fail("不支持的部署目标: $deployTarget");
        }

        $this->guardSdk(function () use ($credentials, $regionId, $deployTarget, $certId, $loadbalancerId, $listenerId) {
            /** @var CtcccloudRestClient $client */
            $client = $this->makeClient('elb', $credentials);

            if ($deployTarget === self::DEPLOY_TARGET_LOADBALANCER) {
                $this->deployToLoadbalancer($client, $regionId, $loadbalancerId, $certId);
            } else {
                $this->updateListener($client, $regionId, $listenerId, $certId);
            }
        });
    }

    /**
     * 列出负载均衡下的 HTTPS 监听器并逐个更新证书。
     */
    private function deployToLoadbalancer(CtcccloudRestClient $client, string $regionId, string $loadbalancerId, string $certId): void
    {
        $resp = $client->get('/v4/elb/list-listener', [
            'regionID' => $regionId,
            'loadBalancerID' => $loadbalancerId,
        ]);

        $listeners = is_array($resp['returnObj'] ?? null) ? $resp['returnObj'] : [];
        foreach ($listeners as $listener) {
            if (! is_array($listener)) {
                continue;
            }
            $protocol = is_string($listener['protocol'] ?? null) ? $listener['protocol'] : '';
            if (strtoupper($protocol) !== 'HTTPS') {
                continue;
            }
            $listenerId = isset($listener['ID']) ? (string) $listener['ID'] : '';
            if ($listenerId === '') {
                continue;
            }
            $this->updateListener($client, $regionId, $listenerId, $certId);
        }
    }

    /**
     * 更新单个监听器的证书。
     */
    private function updateListener(CtcccloudRestClient $client, string $regionId, string $listenerId, string $certId): void
    {
        $client->post('/v4/elb/update-listener', [
            'regionID' => $regionId,
            'listenerID' => $listenerId,
            'certificateID' => $certId,
        ]);
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'elb' => new CtcccloudRestClient(
                'ctelb-global.ctapi.ctyun.cn',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                ['200', '800'],          // ELB 成功码：200 或 800
                true,                    // error 非空即失败……
                ['SUCCESS'],             // ……但放行 error="SUCCESS"
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CtcccloudErrorSanitizer::sanitize($e);
    }
}
