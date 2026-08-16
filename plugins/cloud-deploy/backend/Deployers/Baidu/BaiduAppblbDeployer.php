<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Throwable;

/**
 * 百度智能云应用型负载均衡 AppBLB（证书服务型）：证书先经证书中心上传拿 certId（走 RemoteCertStore 去重），
 * 再把 certId 设到 AppBLB 的 HTTPS/SSL 监听器。
 *
 * 对齐 certimate baiducloud-appblb，与 BLB 形态一致，差异仅两点（封装在 BaiduBlbDeployTrait override 点）：
 * - REST 路径前缀 /v1/appblb（BLB 为 /v1/blb），host 同为 blb.{region}.baidubce.com。
 * - 更新 HTTPS 监听器时 body 须回填 scheduler（certimate UpdateAppHTTPSListenerArgs.Scheduler 取自既有监听）。
 *
 * 端口探测：deploy_target=loadbalancer 走 GET /v1/appblb/{id}（详情 listener[]）；deploy_target=listener 走
 * GET /v1/appblb/{id}/listener?listenerPort=N（DescribeAppAllListeners）。更新 HTTPS/SSL 与 BLB 同结构。
 */
class BaiduAppblbDeployer extends AbstractDeployer
{
    use BaiduBlbDeployTrait;

    public function provider(): string
    {
        return 'baidu';
    }

    public function product(): string
    {
        return 'appblb';
    }

    public function label(): string
    {
        return '百度智能云 应用型 BLB';
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
        return new BaiduCertUploader(fn (array $credentials): object => $this->makeClient('cert', $credentials));
    }

    /** AppBLB REST 路径前缀。 */
    protected function blbUriPrefix(): string
    {
        return '/v1/appblb';
    }

    /** 查询「指定端口的监听」的子路径：AppBLB 为 /v1/appblb/{id}/listener（DescribeAppAllListeners）。 */
    protected function allListenerPath(string $loadbalancerId): string
    {
        return $this->blbUriPrefix()."/$loadbalancerId/listener";
    }

    /** AppBLB 更新 HTTPS 监听须回填 scheduler（取自既有监听器）。 */
    protected function httpsUpdateExtra(object $existingListener): array
    {
        $scheduler = $existingListener->scheduler ?? null;

        return is_string($scheduler) && $scheduler !== '' ? ['scheduler' => $scheduler] : [];
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'cert' => new BaiduRestClient('certificate.baidubce.com', $credentials),
            // AppBLB 与 BLB 同 host（blb.{region}.baidubce.com），仅路径前缀不同；region 可经 :port/ 注入（反模式 18）
            'blb' => new BaiduRestClient($this->authorizedRegionHost("blb.$region.baidubce.com"), $credentials),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    private function authorizedRegionHost(string $host): string
    {
        app(OutboundDestinationPolicy::class)->authorizeOfficialHost($this->provider(), $host);

        return $host;
    }

    protected function sanitize(Throwable $e): string
    {
        return BaiduErrorSanitizer::sanitize($e);
    }
}
