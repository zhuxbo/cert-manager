<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 百度智能云普通型负载均衡 BLB（证书服务型）：证书先经证书中心上传拿 certId（走 RemoteCertStore 去重），
 * 再把 certId 设到 BLB 的 HTTPS/SSL 监听器。
 *
 * 对齐 certimate baiducloud-blb，REST endpoint 路径前缀 /v1/blb，host blb.{region}.baidubce.com：
 * - deploy_target=loadbalancer：GET /v1/blb/{id}（实例详情）→ listener[] 过滤 HTTPS/SSL → 逐个更新。
 * - deploy_target=listener：GET /v1/blb/{id}/listener?listenerPort=N（查所有监听）→ 该端口的 HTTPS/SSL → 更新。
 * - 更新 HTTPS：PUT /v1/blb/{id}/HTTPSlistener?listenerPort=N&clientToken=X
 *     · 无 SNI（domain 空）：body {listenerPort, certIds:[certId]}
 *     · 有 SNI（domain）：先 GET HTTPSlistener 取既有 certIds + additionalCertDomains，把匹配 host 的 certId 换成新 certId
 * - 更新 SSL：PUT /v1/blb/{id}/SSLlistener?listenerPort=N&clientToken=X  body {listenerPort, certIds:[certId]}
 *
 * 简化（对齐插件其他端点）：照搬 certimate 的「loadbalancer 遍历全部 HTTPS/SSL 监听」「listener 按端口」两目标，
 * 但不做异步任务轮询（百度监听更新为同步）。clientToken 用 32 字符随机串保证幂等性。
 */
class BaiduBlbDeployer extends AbstractDeployer
{
    use BaiduBlbDeployTrait;

    public function provider(): string
    {
        return 'baidu';
    }

    public function product(): string
    {
        return 'blb';
    }

    public function label(): string
    {
        return '百度智能云 BLB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '负载均衡实例（全部 HTTPS/SSL 监听）', 'value' => 'loadbalancer'],
                ['label' => '指定监听器', 'value' => 'listener'],
            ]],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_port', 'label' => '监听端口（部署目标为指定监听器时必填）', 'type' => 'number', 'required' => false],
            ['key' => 'domain', 'label' => 'SNI 扩展域名（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 证书中心上传为 region-less，与 BLB 的 region 维度无关。
        return new BaiduCertUploader(fn (array $credentials): object => $this->makeClient('cert', $credentials));
    }

    /** BLB REST 路径前缀（AppBLB override 为 /v1/appblb）。 */
    protected function blbUriPrefix(): string
    {
        return '/v1/blb';
    }

    /** 查询「指定端口的监听」的子路径（BLB 为 /listener，AppBLB 为 /listener，但 deserialize 字段一致）。 */
    protected function allListenerPath(string $loadbalancerId): string
    {
        return $this->blbUriPrefix()."/$loadbalancerId/listener";
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            // 证书中心：region-less。
            'cert' => new BaiduRestClient('certificate.baidubce.com', $credentials),
            // BLB：region 维度，host 含 region。
            'blb' => new BaiduRestClient("blb.$region.baidubce.com", $credentials),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BaiduErrorSanitizer::sanitize($e);
    }
}
