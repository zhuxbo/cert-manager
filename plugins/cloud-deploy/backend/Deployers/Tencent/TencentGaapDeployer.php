<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Gaap\V20180529\GaapClient;
use TencentCloud\Gaap\V20180529\Models\DescribeHTTPSListenersRequest;
use TencentCloud\Gaap\V20180529\Models\ModifyHTTPSListenerAttributeRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云全球应用加速 GAAP（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 **独立 GAAP SDK**（gaap/v20180529）把证书设到指定 HTTPS 监听器。
 *
 * **关键：GAAP 不走 SSL DeployCertificateInstance**（区别于本批 cos/ssl-deploy）。certimate
 * tencentcloud-gaap 用独立 GAAP API：DescribeHTTPSListeners（校验监听器存在）+
 * ModifyHTTPSListenerAttribute（设 CertificateId）。故本端点需 GAAP SDK 包（tencentcloud/gaap）。
 *
 * config：
 * - listener_id（必填）→ ListenerId，HTTPS 监听器 ID（certimate DEPLOY_TARGET_LISTENER 必填）
 * - proxy_id（选填）→ ProxyId，通道 ID；通道组监听器可不传，单通道监听器按需传
 *
 * 简化（相对 certimate）：仅实现 DEPLOY_TARGET_LISTENER（指定监听器）核心路径——certimate
 * 同样只支持这一种 deploy target，无遗漏。
 *
 * region 维度：GAAP client certimate 传空 region（用 Endpoint 区分国内/国际站）；本端点
 * 走默认 Endpoint，client 空 region。SSL 上传服务仍为全局（空 region）。
 *
 * 字段大小写（亲读 vendor gaap/v20180529 Models）：ModifyHTTPSListenerAttributeRequest 用
 * ListenerId / ProxyId / CertificateId（常规驼峰，无 WAF 那种 InstanceID 大写陷阱）；
 * DescribeHTTPSListenersResponse 的监听器列表字段为 ListenerSet。
 */
class TencentGaapDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'gaap';
    }

    public function label(): string
    {
        return '腾讯云 GAAP';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'listener_id', 'label' => 'HTTPS 监听器 ID', 'type' => 'string', 'required' => true],
            ['key' => 'proxy_id', 'label' => '通道 ID（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 腾讯 SSL 上传是全局服务（空 region）
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{listener_id:string,proxy_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $listenerId = (string) $this->requireConfig($config, 'listener_id');
        // proxy_id 选填（通道组监听器可省）
        $proxyId = (string) ($config['proxy_id'] ?? '');

        /** @var GaapClient $client */
        $client = $this->makeClient('gaap', $credentials);

        // 先校验 HTTPS 监听器存在（certimate DescribeHTTPSListeners → ListenerSet 非空）。
        // SDK 调用 guardSdk 脱敏；"未找到监听器" 业务判定放 guard 外（否则被 sanitizer 吞成泛化文案）
        $listenerSet = $this->guardSdk(function () use ($client, $listenerId) {
            $descReq = new DescribeHTTPSListenersRequest;
            $descReq->deserialize([
                'ListenerId' => $listenerId,
                'Offset' => 0,
                'Limit' => 1,
            ]);

            return $client->DescribeHTTPSListeners($descReq)->getListenerSet();
        });
        if (empty($listenerSet)) {
            $this->fail("未找到 HTTPS 监听器 $listenerId");
        }

        // 修改监听器证书（ModifyHTTPSListenerAttribute 设 CertificateId）
        $this->guardSdk(function () use ($client, $listenerId, $proxyId, $certRef) {
            $modReq = new ModifyHTTPSListenerAttributeRequest;
            $payload = [
                'ListenerId' => $listenerId,
                'CertificateId' => $certRef,
            ];
            // proxy_id 仅在非空时下发（certimate lo.EmptyableToPtr）
            if ($proxyId !== '') {
                $payload['ProxyId'] = $proxyId;
            }
            $modReq->deserialize($payload);
            $client->ModifyHTTPSListenerAttribute($modReq);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        // SSL 与 GAAP 均走全局/默认 Endpoint，空 region
        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'gaap' => new GaapClient($cred, '', $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
