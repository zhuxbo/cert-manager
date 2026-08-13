<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 火山引擎 ALB 应用型负载均衡（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再设到 ALB 的 HTTPS 监听器。对齐 certimate volcengine-alb（universal/RPC query 协议，GET /）：
 *   - deploy_target=loadbalancer：DescribeListeners(Protocol=HTTPS) 枚举 → 逐个更新
 *   - deploy_target=listener：直接对 listener_id 更新
 *   更新（ModifyListenerAttributes, Version=2020-04-01）按是否指定 SNI domain 分两路：
 *     · 无 domain：{ListenerId, CertificateSource:"cert_center", CertCenterCertificateId}
 *     · 有 domain：先 DescribeListenerAttributes 取 DomainExtensions，把 Domain==domain 的扩展域名换新证书
 *       {ListenerId, DomainExtensions:[{DomainExtensionId, Domain, CertificateSource:"cert_center", CertCenterCertificateId, Action:"modify"}]}
 *
 * region 必填（ALB 为 region 维度）；证书中心上传与 ALB 用同一 region。
 */
class VolcAlbDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'alb';
    }

    public function label(): string
    {
        return '火山引擎 ALB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '负载均衡实例（全部 HTTPS 监听）', 'value' => 'loadbalancer'],
                ['label' => '指定监听器', 'value' => 'listener'],
            ]],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡实例 ID（部署目标为实例时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'listener_id', 'label' => '监听器 ID（部署目标为指定监听器时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => 'SNI 扩展域名（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // RegistryCompleteness 以空 config 探活：region 优雅默认（真实 region 由 bind 强校验），不抛
        $region = (string) ($config['region'] ?? '');

        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * @param  string  $certRef  证书中心 InstanceId
     * @param  array{access_key_id?:string,secret_access_key?:string,project_name?:string}  $credentials
     * @param  array{region:string,deploy_target:string,loadbalancer_id?:string,listener_id?:string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // 业务校验全在 guardSdk 之外（业务错误不进 guardSdk，否则被重建成无 message 的 SDK 异常）
        $region = (string) $this->requireConfig($config, 'region');
        $deployTarget = (string) $this->requireConfig($config, 'deploy_target');
        $domain = $config['domain'] ?? '';
        $certId = (string) $certRef;

        $loadbalancerId = '';
        $listenerId = '';
        if ($deployTarget === 'loadbalancer') {
            $loadbalancerId = (string) $this->requireConfig($config, 'loadbalancer_id');
        } elseif ($deployTarget === 'listener') {
            $listenerId = (string) $this->requireConfig($config, 'listener_id');
        } else {
            $this->fail("不支持的部署目标 $deployTarget");
        }

        // 枚举监听器（SDK 读，guardSdk 包裹后返回）
        $listenerIds = $deployTarget === 'loadbalancer'
            ? $this->guardSdk(fn (): array => $this->listHttpsListeners(
                $this->makeClient('alb', $credentials, $region),
                $loadbalancerId,
                (string) ($credentials['project_name'] ?? ''),
            ))
            : [$listenerId];

        foreach ($listenerIds as $id) {
            $this->updateListenerCertificate($credentials, $region, $id, $certId, $domain);
        }
    }

    /** 更新单个监听器证书：无 SNI 直接设主证书；有 SNI 替换匹配的扩展域名证书。 */
    private function updateListenerCertificate(array $credentials, string $region, string $listenerId, string $certId, string $domain): void
    {
        if ($domain === '') {
            $this->guardSdk(function () use ($credentials, $region, $listenerId, $certId) {
                /** @var VolcRestClient $client */
                $client = $this->makeClient('alb', $credentials, $region);
                $client->callQuery('ModifyListenerAttributes', '2020-04-01', [
                    'ListenerId' => $listenerId,
                    'CertificateSource' => 'cert_center',
                    'CertCenterCertificateId' => $certId,
                ]);
            });

            return;
        }

        // SNI：取既有扩展域名（SDK 读，guardSdk 包裹），匹配/组装在外，未匹配走业务错误
        $extensions = $this->guardSdk(function () use ($credentials, $region, $listenerId): array {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('alb', $credentials, $region);
            $attrs = $client->callQuery('DescribeListenerAttributes', '2020-04-01', ['ListenerId' => $listenerId]);

            return is_array($attrs['DomainExtensions'] ?? null) ? $attrs['DomainExtensions'] : [];
        });

        $modified = [];
        foreach ($extensions as $ext) {
            if (! is_array($ext) || ($ext['Domain'] ?? null) !== $domain) {
                continue;
            }
            $modified[] = [
                'DomainExtensionId' => (string) ($ext['DomainExtensionId'] ?? ''),
                'Domain' => $domain,
                'CertificateSource' => 'cert_center',
                'CertCenterCertificateId' => $certId,
                'Action' => 'modify',
            ];
        }

        // 业务错误：未找到匹配 SNI 扩展域名（在 guardSdk 之外）
        if ($modified === []) {
            $this->fail("监听器 $listenerId 未找到 SNI 扩展域名 $domain");
        }

        $this->guardSdk(function () use ($credentials, $region, $listenerId, $modified) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('alb', $credentials, $region);
            $client->callQuery('ModifyListenerAttributes', '2020-04-01', [
                'ListenerId' => $listenerId,
                'DomainExtensions' => $modified,
            ]);
        });
    }

    /**
     * 枚举实例下全部 HTTPS 监听器 id（DescribeListeners 分页，universal query GET）。
     *
     * @return list<string>
     */
    private function listHttpsListeners(VolcRestClient $client, string $loadbalancerId, string $projectName): array
    {
        $client->callQuery('DescribeLoadBalancerAttributes', '2020-04-01', [
            'LoadBalancerId' => $loadbalancerId,
        ]);
        $ids = [];
        $page = 1;
        $pageSize = 100;
        do {
            $params = [
                'LoadBalancerId' => $loadbalancerId,
                'Protocol' => 'HTTPS',
                'PageNumber' => $page,
                'PageSize' => $pageSize,
            ];
            if ($projectName !== '') {
                $params['ProjectName'] = $projectName;
            }
            $result = $client->callQuery('DescribeListeners', '2020-04-01', $params);

            $listeners = is_array($result['Listeners'] ?? null) ? $result['Listeners'] : [];
            foreach ($listeners as $listener) {
                $id = is_array($listener) ? ($listener['ListenerId'] ?? null) : null;
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
            $page++;
        } while (count($listeners) >= $pageSize);

        return $ids;
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'alb' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'alb',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'certcenter' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'certificate_service',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}
