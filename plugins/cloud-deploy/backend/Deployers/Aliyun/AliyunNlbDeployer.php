<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Nlb\V20220430\Models\GetLoadBalancerAttributeRequest;
use AlibabaCloud\SDK\Nlb\V20220430\Models\ListListenersRequest;
use AlibabaCloud\SDK\Nlb\V20220430\Models\UpdateListenerAttributeRequest;
use AlibabaCloud\SDK\Nlb\V20220430\Nlb;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 NLB（网络型负载均衡，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 nlb.UpdateListenerAttribute 把证书关联到指定 TCPSSL 监听器。
 *
 * 与 alb 一样，NLB 监听 API 吃**完整 CertIdentifier 字符串**作为 CertificateIds 元素，**不**拆 certId+region。
 * 与 alb 的形参差异：NLB 的 certificateIds 是**扁平 string 数组**（非嵌套对象，对齐 certimate aliyun-nlb）。
 * certimate 在 update 前先 GetListenerAttribute 读一次，但其响应仅用于日志、不影响 update 入参，故此处省略。
 *
 * 支持 listener 与 loadbalancer 两种目标；后者分页列出负载均衡下全部 TCPSSL 监听并批量更新。
 */
class AliyunNlbDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig, MatchesAliyunDomains;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'nlb';
    }

    public function label(): string
    {
        return '阿里云 NLB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => false, 'default' => 'listener'],
            ['key' => 'load_balancer_id', 'label' => '负载均衡实例 ID', 'type' => 'string', 'required' => false],
            ['key' => 'listener_id', 'label' => '监听 ID', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials), $this->casRegion($config));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 CertificateIds 元素）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{region:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $target = strtolower((string) ($config['deploy_target'] ?? 'listener'));
        $listenerId = (string) ($config['listener_id'] ?? '');
        $loadBalancerId = (string) ($config['load_balancer_id'] ?? '');
        if ($target === 'listener' && $listenerId === '') {
            $this->requireConfig($config, 'listener_id');
        }
        if ($target === 'loadbalancer' && $loadBalancerId === '') {
            $this->requireConfig($config, 'load_balancer_id');
        }
        if (! in_array($target, ['listener', 'loadbalancer'], true)) {
            $this->fail("Aliyun NLB 不支持的部署目标: $target");
        }
        $certificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $target, $listenerId, $loadBalancerId, $certificateId) {
            /** @var Nlb $client */
            $client = $this->makeClient('nlb', $credentials, $region);
            $listenerIds = $target === 'loadbalancer'
                ? $this->findLoadBalancerListeners($client, $loadBalancerId)
                : [$listenerId];
            foreach ($listenerIds as $matchedListenerId) {
                $client->updateListenerAttribute(new UpdateListenerAttributeRequest([
                    'listenerId' => $matchedListenerId,
                    'certificateIds' => [$certificateId],
                ]));
            }
        });
    }

    /** @return list<string> */
    private function findLoadBalancerListeners(Nlb $client, string $loadBalancerId): array
    {
        $client->getLoadBalancerAttribute(new GetLoadBalancerAttributeRequest([
            'loadBalancerId' => $loadBalancerId,
        ]));

        $listenerIds = [];
        $nextToken = null;
        do {
            $response = $client->listListeners(new ListListenersRequest([
                'nextToken' => $nextToken,
                'maxResults' => 100,
                'loadBalancerIds' => [$loadBalancerId],
                'listenerProtocol' => 'TCPSSL',
            ]));
            $items = is_array($response->body?->listeners ?? null) ? $response->body->listeners : [];
            foreach ($items as $item) {
                $id = (string) ($item->listenerId ?? '');
                if ($id !== '') {
                    $listenerIds[] = $id;
                }
            }
            $nextToken = $response->body->nextToken ?? null;
        } while (is_string($nextToken) && $nextToken !== '');

        return $listenerIds;
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {

        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, $this->casEndpoint($credentials))),
            'nlb' => new Nlb($this->aliyunConfig($credentials, $region !== '' ? "nlb.$region.aliyuncs.com" : 'nlb.cn-hangzhou.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
