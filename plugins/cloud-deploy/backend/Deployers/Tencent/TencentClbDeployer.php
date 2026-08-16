<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\PollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\ResumesRemoteJob;
use TencentCloud\Clb\V20180317\ClbClient;
use TencentCloud\Clb\V20180317\Models\DescribeListenersRequest;
use TencentCloud\Clb\V20180317\Models\DescribeTaskStatusRequest;
use TencentCloud\Clb\V20180317\Models\ModifyDomainAttributesRequest;
use TencentCloud\Clb\V20180317\Models\ModifyListenerRequest;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云负载均衡 CLB（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 CLB（clb/v20180317）DescribeListeners + ModifyListener 把证书设到指定监听器，
 * 保留监听器已有的 SSLMode 与 CertCaId，未配置时才默认 UNIDIRECTIONAL。
 *
 * 简化：仅实现「指定 listener_id 设证书」核心路径（对应 certimate tencentcloud-clb
 * DEPLOY_TARGET_LISTENER）；不遍历负载均衡所有 HTTPS/TCP_SSL/QUIC 监听（loadbalancer 模式）、
 * 不做七层规则域名 SNI（ModifyDomainAttributes / ruledomain 模式）、不轮询 DescribeTaskStatus 异步任务。
 *
 * **与全局证书服务（cdn/css/vod）的本质差异**：CLB 接口是 region 维度，client 构造必须带 region，
 * 故 region 为必填配置，由 config.region 注入 makeClient；SSL 上传服务仍为全局（空 region）。
 * ModifyListener 只传 LoadBalancerId+ListenerId+Certificate，其余监听属性不传即保留。
 */
class TencentClbDeployer extends AbstractDeployer implements HasPollBudget, ResumesRemoteJob
{
    use UsesTencentEndpoint;

    public const CLIENT_TIMEOUT_SECONDS = 10;

    protected int $maxPollAttempts = 1;

    protected int $resumePollAttempts = 1;

    protected int $pollIntervalSeconds = 0;

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
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'select', 'required' => false, 'default' => 'listener', 'options' => [
                ['label' => '负载均衡实例', 'value' => 'loadbalancer'],
                ['label' => '指定监听器', 'value' => 'listener'],
                ['label' => '七层规则域名', 'value' => 'ruledomain'],
            ]],
            ['key' => 'load_balancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听器 ID', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => '七层规则域名', 'type' => 'string', 'required' => false],
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
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $this->withTencentEndpoint($credentials, $config)));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{deploy_target?:string,load_balancer_id:string,listener_id?:string,domain?:string,rule_domains?:list<string>|string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $loadBalancerId = (string) $this->requireConfig($config, 'load_balancer_id');
        $deployTarget = isset($config['deploy_target']) && $config['deploy_target'] !== ''
            ? (string) $config['deploy_target']
            : 'listener';
        $listenerId = in_array($deployTarget, ['listener', 'ruledomain'], true)
            ? (string) $this->requireConfig($config, 'listener_id')
            : '';
        $domain = $deployTarget === 'ruledomain' ? (string) $this->requireConfig($config, 'domain') : '';
        $region = (string) $this->requireConfig($config, 'region');
        $certId = (string) $certRef;

        /** @var ClbClient $client */
        $client = $this->makeClient('clb', $credentials, $region);

        if ($deployTarget === 'loadbalancer') {
            $listeners = $this->guardSdk(function () use ($client, $loadBalancerId) {
                $describe = new DescribeListenersRequest;
                $describe->deserialize(['LoadBalancerId' => $loadBalancerId]);

                return $client->DescribeListeners($describe)->getListeners();
            });
            $listenerIds = [];
            foreach ($listeners as $listener) {
                $protocol = $listener->getProtocol();
                $id = $listener->getListenerId();
                if (in_array($protocol, ['HTTPS', 'TCP_SSL', 'QUIC'], true) && is_string($id) && $id !== '') {
                    $listenerIds[] = $id;
                }
            }
            $this->continueListenerQueue($client, $loadBalancerId, $certId, $listenerIds);

            return;
        }

        if ($deployTarget === 'listener') {
            $this->continueListenerQueue($client, $loadBalancerId, $certId, [$listenerId]);

            return;
        }

        if ($deployTarget === 'ruledomain') {
            $taskId = $this->guardSdk(function () use ($client, $loadBalancerId, $listenerId, $domain, $certId) {
                $request = new ModifyDomainAttributesRequest;
                $request->deserialize([
                    'LoadBalancerId' => $loadBalancerId,
                    'ListenerId' => $listenerId,
                    'Domain' => $domain,
                    'Certificate' => ['SSLMode' => 'UNIDIRECTIONAL', 'CertId' => $certId],
                ]);

                return $client->ModifyDomainAttributes($request)->getRequestId();
            });
            if ($taskId !== '') {
                $state = ['kind' => 'task', 'task_id' => $taskId];
                try {
                    $this->pollTask($client, $taskId, $this->maxPollAttempts);
                } catch (DeployPollPendingException) {
                    throw new DeployPollPendingException($this->encodeState($state), '腾讯云 CLB 规则域名证书任务处理中，待确认');
                }
            }

            return;
        }

        $this->fail("不支持的部署目标 $deployTarget");
    }

    public function resumePoll(string $remoteJobId, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $region = (string) $this->requireConfig($config, 'region');
        /** @var ClbClient $client */
        $client = $this->makeClient('clb', $credentials, $region);
        $state = $this->decodeState($remoteJobId);
        if ($state === null) {
            $this->pollTask($client, $remoteJobId, $this->resumePollAttempts);

            return;
        }

        $taskId = is_string($state['task_id'] ?? null) ? $state['task_id'] : '';
        if ($taskId !== '') {
            try {
                $this->pollTask($client, $taskId, $this->resumePollAttempts);
            } catch (DeployPollPendingException) {
                throw new DeployPollPendingException($remoteJobId, '腾讯云 CLB 证书任务处理中，待确认');
            }
        }

        if (($state['kind'] ?? null) === 'listener_queue') {
            $loadBalancerId = is_string($state['load_balancer_id'] ?? null) ? $state['load_balancer_id'] : '';
            $certId = is_string($state['cert_id'] ?? null) ? $state['cert_id'] : '';
            $remaining = is_array($state['remaining'] ?? null)
                ? array_values(array_filter($state['remaining'], 'is_string'))
                : [];
            if ($loadBalancerId === '' || $certId === '') {
                throw new DeployBusinessException('腾讯云 CLB 续查状态无效');
            }
            $this->continueListenerQueue($client, $loadBalancerId, $certId, $remaining);
        }
    }

    public function pollBudget(): PollBudget
    {
        return new PollBudget(
            clientTimeoutSeconds: self::CLIENT_TIMEOUT_SECONDS,
            uploadCalls: 1,
            preIterCalls: 3,
            bindIterations: $this->maxPollAttempts,
            intervalSeconds: $this->pollIntervalSeconds,
        );
    }

    /** @param list<string> $listenerIds */
    private function continueListenerQueue(object $client, string $loadBalancerId, string $certId, array $listenerIds): void
    {
        if ($listenerIds === []) {
            return;
        }
        $listenerId = (string) array_shift($listenerIds);
        $taskId = $this->updateListenerCertificate($client, $loadBalancerId, $listenerId, $certId);
        $state = [
            'kind' => 'listener_queue',
            'task_id' => $taskId,
            'load_balancer_id' => $loadBalancerId,
            'cert_id' => $certId,
            'remaining' => $listenerIds,
        ];
        if ($taskId !== '') {
            try {
                $this->pollTask($client, $taskId, $this->maxPollAttempts);
            } catch (DeployPollPendingException) {
                throw new DeployPollPendingException($this->encodeState($state), '腾讯云 CLB 监听器证书任务处理中，待确认');
            }
        }
        if ($listenerIds !== []) {
            $state['task_id'] = '';
            throw new DeployPollPendingException($this->encodeState($state), '腾讯云 CLB 尚有监听器待更新');
        }
    }

    private function updateListenerCertificate(object $client, string $loadBalancerId, string $listenerId, string $certId): string
    {
        $listeners = $this->guardSdk(function () use ($client, $loadBalancerId, $listenerId) {
            $describe = new DescribeListenersRequest;
            $describe->deserialize([
                'LoadBalancerId' => $loadBalancerId,
                'ListenerIds' => [$listenerId],
            ]);

            return $client->DescribeListeners($describe)->getListeners();
        });
        if (! is_array($listeners) || $listeners === []) {
            $this->fail("腾讯云 CLB 未找到监听器 $listenerId");
        }

        $certificate = $listeners[0]->getCertificate();
        $sslMode = $certificate?->getSSLMode();
        $certCaId = $certificate?->getCertCaId();

        $taskId = $this->guardSdk(function () use ($client, $loadBalancerId, $listenerId, $certId, $sslMode, $certCaId) {
            $req = new ModifyListenerRequest;
            $certificatePayload = [
                'SSLMode' => is_string($sslMode) && $sslMode !== '' ? $sslMode : 'UNIDIRECTIONAL',
                'CertId' => $certId,
            ];
            if (is_string($certCaId) && $certCaId !== '') {
                $certificatePayload['CertCaId'] = $certCaId;
            }
            $req->deserialize([
                'LoadBalancerId' => $loadBalancerId,
                'ListenerId' => $listenerId,
                'Certificate' => $certificatePayload,
            ]);

            return $client->ModifyListener($req)->getRequestId();
        });

        return is_string($taskId) ? $taskId : '';
    }

    private function pollTask(object $client, string $taskId, int $attempts): void
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $status = $this->guardSdk(function () use ($client, $taskId) {
                $request = new DescribeTaskStatusRequest;
                $request->deserialize(['TaskId' => $taskId]);

                return $client->DescribeTaskStatus($request)->getStatus();
            });
            if ($status === 0) {
                return;
            }
            if ($status === 1) {
                throw new DeployBusinessException('腾讯云 CLB 证书部署任务失败');
            }
            if ($attempt < $attempts - 1) {
                $this->sleep($this->pollIntervalSeconds);
            }
        }

        throw new DeployPollPendingException($taskId, '腾讯云 CLB 证书部署任务处理中，待确认');
    }

    /** @param array<string,mixed> $state */
    private function encodeState(array $state): string
    {
        return 'clb:'.base64_encode((string) json_encode($state, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed>|null */
    private function decodeState(string $remoteJobId): ?array
    {
        if (! str_starts_with($remoteJobId, 'clb:')) {
            return null;
        }
        $json = base64_decode(substr($remoteJobId, 4), true);
        if (! is_string($json)) {
            return null;
        }
        $state = json_decode($json, true);

        return is_array($state) ? $state : null;
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(self::CLIENT_TIMEOUT_SECONDS);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            // SSL 全局服务（certimate 亦传空 region）
            'ssl' => new SslClient($cred, '', $profile),
            // CLB region 维度：client 构造必须带 region（certimate createSDKClient 传 config.Region）
            'clb' => new ClbClient($cred, $region, $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
