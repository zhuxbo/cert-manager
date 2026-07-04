<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Clb\V20180317\ClbClient;
use TencentCloud\Clb\V20180317\Models\ModifyListenerRequest;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云负载均衡 CLB（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 CLB（clb/v20180317）ModifyListener 以 Certificate.{CertId,SSLMode=UNIDIRECTIONAL}
 * 把证书设到指定监听器（ListenerId）。
 *
 * 简化：仅实现「指定 listener_id 设证书」核心路径（对应 certimate tencentcloud-clb
 * DEPLOY_TARGET_LISTENER）；不遍历负载均衡所有 HTTPS/TCP_SSL/QUIC 监听（loadbalancer 模式）、
 * 不做七层规则域名 SNI（ModifyDomainAttributes / ruledomain 模式）、不轮询 DescribeTaskStatus 异步任务。
 *
 * **与全局证书服务（cdn/css/vod）的本质差异**：CLB 接口是 region 维度，client 构造必须带 region，
 * 故 region 为必填配置，由 config.region 注入 makeClient；SSL 上传服务仍为全局（空 region）。
 * ModifyListener 只传 LoadBalancerId+ListenerId+Certificate，其余监听属性不传即保留。
 */
class TencentClbDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'clb';
    }

    public function label(): string
    {
        return '腾讯云 CLB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'load_balancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听器 ID', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 腾讯 SSL 上传是全局服务（空 region），与 CLB 的 region 维度无关
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{load_balancer_id:string,listener_id:string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $loadBalancerId = (string) $this->requireConfig($config, 'load_balancer_id');
        $listenerId = (string) $this->requireConfig($config, 'listener_id');
        $region = (string) $this->requireConfig($config, 'region');

        $this->guardSdk(function () use ($credentials, $region, $loadBalancerId, $listenerId, $certRef) {
            /** @var ClbClient $client */
            $client = $this->makeClient('clb', $credentials, $region);
            $req = new ModifyListenerRequest;
            // Certificate 子结构：SSLMode 大写 SSL（CertificateInput::setSSLMode），CertId 正常拼写
            $req->deserialize([
                'LoadBalancerId' => $loadBalancerId,
                'ListenerId' => $listenerId,
                'Certificate' => [
                    'SSLMode' => 'UNIDIRECTIONAL',
                    'CertId' => $certRef,
                ],
            ]);
            $client->ModifyListener($req);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            // SSL 全局服务（certimate 亦传空 region）
            'ssl' => new SslClient($cred, '', $profile),
            // CLB region 维度：client 构造必须带 region（certimate createSDKClient 传 config.Region）
            'clb' => new ClbClient($cred, $region, $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
