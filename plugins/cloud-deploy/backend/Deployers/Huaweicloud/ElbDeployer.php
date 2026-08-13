<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 华为云弹性负载均衡 ELB（证书服务型，region 维度，绑定监听器）。
 *
 * 对齐 certimate deployer huaweicloud-elb 的 DEPLOY_TARGET_LISTENER 核心路径：
 *   1. 经 HuaweiElbUploader 创建 ELB 证书拿 certificate.id（store_kind=huawei_elb:{region}，走 RemoteCertStore 去重）。
 *      （上传器内先 IAM 反查 projectId 再 CreateCertificate。）
 *   2. bind 用 global 凭证 IAM 反查 projectId，ShowListener 后 UpdateListener（PUT /v3/{project_id}/elb/listeners/{id}）
 *      把 default_tls_container_ref 指向新证书 id。
 *
 * ELB 为 region 服务。支持 loadbalancer/listener/certificate 三类目标；certificate 由 uploader 原地替换。
 */
class ElbDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'elb';
    }

    public function label(): string
    {
        return '华为云弹性负载均衡 ELB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => false, 'default' => 'listener'],
            ['key' => 'load_balancer_id', 'label' => '负载均衡器 ID', 'type' => 'string', 'required' => false],
            ['key' => 'listener_id', 'label' => '监听器 ID', 'type' => 'string', 'required' => false],
            ['key' => 'certificate_id', 'label' => '证书 ID', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // ELB 证书 region 维度：据 config.region 构造（空 config 探活时 region 空，storeKind 回落 default、不抛）。
        $region = isset($config['region']) ? (string) $config['region'] : '';
        $replaceCertificateId = strtolower((string) ($config['deploy_target'] ?? 'listener')) === 'certificate'
            ? (string) ($config['certificate_id'] ?? '')
            : '';

        return new HuaweiElbUploader(
            $region,
            fn (array $credentials): object => $this->makeClient('iam', $credentials),
            fn (array $credentials, string $projectId): object => $this->makeClient('elb', $credentials, $region, $projectId),
            $replaceCertificateId,
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（ELB certificate.id）
     * @param  array<string,mixed>  $credentials
     * @param  array{region:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $target = strtolower((string) ($config['deploy_target'] ?? 'listener'));
        $listenerId = (string) ($config['listener_id'] ?? '');
        $loadBalancerId = (string) ($config['load_balancer_id'] ?? '');
        $certificateId = (string) ($config['certificate_id'] ?? '');
        if ($target === 'listener' && $listenerId === '') {
            $this->requireConfig($config, 'listener_id');
        }
        if ($target === 'loadbalancer' && $loadBalancerId === '') {
            $this->requireConfig($config, 'load_balancer_id');
        }
        if ($target === 'certificate') {
            if ($certificateId === '') {
                $this->requireConfig($config, 'certificate_id');
            }

            return;
        }
        if (! in_array($target, ['listener', 'loadbalancer'], true)) {
            $this->fail("Huawei ELB 不支持的部署目标: $target");
        }
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $target, $listenerId, $loadBalancerId, $certId) {
            $projectId = $this->resolveProjectId($credentials, $region);

            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('elb', $credentials, $region, $projectId);

            $listenerIds = $target === 'loadbalancer'
                ? $this->findLoadBalancerListeners($client, $projectId, $loadBalancerId, $credentials)
                : [$listenerId];
            foreach ($listenerIds as $matchedListenerId) {
                $showListener = $client->get("/v3/$projectId/elb/listeners/$matchedListenerId", []);
                $listener = is_array($showListener['listener'] ?? null) ? $showListener['listener'] : [];
                $update = ['default_tls_container_ref' => $certId];
                $sniRefs = is_array($listener['sni_container_refs'] ?? null) ? $listener['sni_container_refs'] : [];
                if ($sniRefs !== []) {
                    $oldResponse = $client->get("/v3/$projectId/elb/certificates", [
                        'id' => implode(',', array_map('strval', $sniRefs)),
                    ]);
                    $newResponse = $client->get("/v3/$projectId/elb/certificates/$certId", []);
                    $oldCertificates = is_array($oldResponse['certificates'] ?? null) ? $oldResponse['certificates'] : [];
                    $newCertificate = is_array($newResponse['certificate'] ?? null) ? $newResponse['certificate'] : [];
                    $newSans = $this->certificateNames($newCertificate);
                    $preserved = [];
                    foreach ($oldCertificates as $oldCertificate) {
                        if (! is_array($oldCertificate) || $this->certificateNames($oldCertificate) === $newSans) {
                            continue;
                        }
                        $id = (string) ($oldCertificate['id'] ?? '');
                        if ($id !== '') {
                            $preserved[] = $id;
                        }
                    }
                    $update['sni_container_refs'] = array_values(array_unique([$certId, ...$preserved]));
                    if ((string) ($listener['sni_match_algo'] ?? '') !== '') {
                        $update['sni_match_algo'] = (string) $listener['sni_match_algo'];
                    }
                }
                $client->put("/v3/$projectId/elb/listeners/$matchedListenerId", [
                    'listener' => $update,
                ]);
            }
        });
    }

    /** @return list<string> */
    private function certificateNames(array $certificate): array
    {
        $names = is_array($certificate['subject_alternative_names'] ?? null)
            ? array_values(array_map('strval', $certificate['subject_alternative_names']))
            : [];
        if ($names === [] && (string) ($certificate['domain'] ?? '') !== '') {
            $names[] = (string) $certificate['domain'];
        }
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function findLoadBalancerListeners(
        HuaweicloudRestClient $client,
        string $projectId,
        string $loadBalancerId,
        array $credentials,
    ): array {
        $client->get("/v3/$projectId/elb/loadbalancers/$loadBalancerId", []);
        $listenerIds = [];
        $marker = '';
        do {
            $query = [
                'limit' => 2000,
                'protocol' => 'HTTPS,TERMINATED_HTTPS',
                'loadbalancer_id' => $loadBalancerId,
            ];
            if (($credentials['enterprise_project_id'] ?? '') !== '') {
                $query['enterprise_project_id'] = (string) $credentials['enterprise_project_id'];
            }
            if ($marker !== '') {
                $query['marker'] = $marker;
            }
            $response = $client->get("/v3/$projectId/elb/listeners", $query);
            $items = is_array($response['listeners'] ?? null) ? $response['listeners'] : [];
            foreach ($items as $item) {
                $id = is_array($item) ? (string) ($item['id'] ?? '') : '';
                if ($id !== '') {
                    $listenerIds[] = $id;
                }
            }
            $marker = (string) ($response['page_info']['next_marker'] ?? '');
        } while ($items !== [] && $marker !== '');

        return $listenerIds;
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
    {
        return match ($kind) {
            'iam' => new HuaweicloudRestClient(
                $this->iamHost(),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'elb' => new HuaweicloudRestClient(
                $this->regionalHost('elb', $region),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                $projectId,
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
