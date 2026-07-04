<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

/**
 * 百度 BLB / AppBLB 监听器绑定证书的共享逻辑（两者 REST 形态一致，仅路径前缀与 AppBLB HTTPS 更新需带 scheduler 不同）。
 *
 * 宿主 deployer 须提供：
 *   - blbUriPrefix(): string                      —— /v1/blb 或 /v1/appblb
 *   - makeClient('blb', creds, region): BaiduRestClient
 *   - requireConfig()/fail()/guardSdk()           —— 来自 AbstractDeployer
 *
 * 可选 override 点（AppBLB 用）：
 *   - httpsUpdateExtra(object $listener): array    —— 更新 HTTPS 监听时附加的 body 字段（AppBLB 需 scheduler）
 *
 * requireConfig 顺序契约：region → deploy_target → loadbalancer_id 全在任何 SDK 调用之前读取（均为 required），
 * listener_port 仅在 listener 分支读取（schema 标 optional，避免「loadbalancer 目标不读 listener_port」成僵尸 required），
 * domain 为可选 SNI（不经 requireConfig）。ConfigSchemaContractTest 依赖此「requireConfig 早于 SDK 调用」。
 */
trait BaiduBlbDeployTrait
{
    /**
     * @param  string  $certRef  remote_cert_id（证书中心 certId）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{region:string,deploy_target:string,loadbalancer_id:string,listener_port?:int|string,domain?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $target = (string) $this->requireConfig($config, 'deploy_target');
        $loadbalancerId = (string) $this->requireConfig($config, 'loadbalancer_id');
        $certId = (string) $certRef;
        // SNI 扩展域名（选填）：空字符串视为未指定。
        $domain = isset($config['domain']) ? (string) $config['domain'] : '';

        switch ($target) {
            case 'loadbalancer':
                $this->deployToLoadbalancer($credentials, $region, $loadbalancerId, $certId, $domain);
                break;

            case 'listener':
                $listenerPort = (int) $this->requireConfig($config, 'listener_port');
                $this->deployToListener($credentials, $region, $loadbalancerId, $listenerPort, $certId, $domain);
                break;

            default:
                $this->fail("不支持的部署目标 deploy_target: $target");
        }
    }

    /**
     * loadbalancer 目标：查实例详情拿全部 HTTPS/SSL 监听端口，逐个更新证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToLoadbalancer(array $credentials, string $region, string $loadbalancerId, string $certId, string $domain): void
    {
        $listeners = $this->guardSdk(function () use ($credentials, $region, $loadbalancerId): array {
            /** @var BaiduRestClient $client */
            $client = $this->makeClient('blb', $credentials, $region);
            // GET /v1/blb/{id} → {listener:[{port(str), type}]}
            $detail = $client->request('GET', $this->blbUriPrefix()."/$loadbalancerId", null, []);

            $result = [];
            foreach ($this->asArray($detail->listener ?? null) as $item) {
                $type = strtoupper((string) ($item->type ?? ''));
                $port = (int) ($item->port ?? 0);
                if (($type === 'HTTPS' || $type === 'SSL') && $port > 0) {
                    $result[] = ['type' => $type, 'port' => $port];
                }
            }

            return $result;
        });

        $this->updateListeners($credentials, $region, $loadbalancerId, $listeners, $certId, $domain);
    }

    /**
     * listener 目标：查指定端口的监听，确认其类型（HTTPS/SSL），更新证书。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function deployToListener(array $credentials, string $region, string $loadbalancerId, int $listenerPort, string $certId, string $domain): void
    {
        $listeners = $this->guardSdk(function () use ($credentials, $region, $loadbalancerId, $listenerPort): array {
            /** @var BaiduRestClient $client */
            $client = $this->makeClient('blb', $credentials, $region);
            // GET /v1/blb/{id}/listener?listenerPort=N → {listenerList:[{listenerPort, listenerType}]}
            $resp = $client->request('GET', $this->allListenerPath($loadbalancerId), null, ['listenerPort' => $listenerPort]);

            $result = [];
            foreach ($this->asArray($resp->listenerList ?? null) as $item) {
                $type = strtoupper((string) ($item->listenerType ?? ''));
                $port = (int) ($item->listenerPort ?? 0);
                if (($type === 'HTTPS' || $type === 'SSL') && $port > 0) {
                    $result[] = ['type' => $type, 'port' => $port];
                }
            }

            return $result;
        });

        if ($listeners === []) {
            $this->fail("未找到端口 $listenerPort 的 HTTPS/SSL 监听器");
        }

        $this->updateListeners($credentials, $region, $loadbalancerId, $listeners, $certId, $domain);
    }

    /**
     * 逐个把 certId 设到监听器（HTTPS 走 SNI 感知更新，SSL 直接设）。
     *
     * @param  array<string,mixed>  $credentials
     * @param  list<array{type:string,port:int}>  $listeners
     */
    private function updateListeners(array $credentials, string $region, string $loadbalancerId, array $listeners, string $certId, string $domain): void
    {
        foreach ($listeners as $listener) {
            if ($listener['type'] === 'HTTPS') {
                $this->updateHttpsListener($credentials, $region, $loadbalancerId, $listener['port'], $certId, $domain);
            } else {
                $this->updateSslListener($credentials, $region, $loadbalancerId, $listener['port'], $certId);
            }
        }
    }

    /**
     * 更新 HTTPS 监听器证书（SNI 感知）。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function updateHttpsListener(array $credentials, string $region, string $loadbalancerId, int $port, string $certId, string $domain): void
    {
        $this->guardSdk(function () use ($credentials, $region, $loadbalancerId, $port, $certId, $domain) {
            /** @var BaiduRestClient $client */
            $client = $this->makeClient('blb', $credentials, $region);
            $path = $this->blbUriPrefix()."/$loadbalancerId/HTTPSlistener";

            // 先查该 HTTPS 监听器现状（取 scheduler / 既有 certIds / additionalCertDomains）。
            $resp = $client->request('GET', $path, null, ['listenerPort' => $port, 'maxKeys' => 1]);
            $existing = $this->asArray($resp->listenerList ?? null)[0] ?? null;
            if ($existing === null) {
                $this->fail("未找到端口 $port 的 HTTPS 监听器");
            }

            $body = [
                'listenerPort' => $port,
            ];
            // AppBLB 的 HTTPS 更新需回填 scheduler（BLB 无此要求，trait 默认返回空数组）。
            $body += $this->httpsUpdateExtra($existing);

            if ($domain === '') {
                // 无 SNI：直接把主证书设为新 certId。
                $body['certIds'] = [$certId];
            } else {
                // 有 SNI：保留主证书，只替换 additionalCertDomains 里匹配 host 的证书。
                $body['certIds'] = $this->stringList($existing->certIds ?? null);
                $body['additionalCertDomains'] = array_map(
                    function (object $d) use ($domain, $certId): array {
                        $host = (string) ($d->host ?? '');

                        return [
                            'host' => $host,
                            'certId' => $host === $domain ? $certId : (string) ($d->certId ?? ''),
                        ];
                    },
                    $this->asArray($existing->additionalCertDomains ?? null),
                );
            }

            // PUT /v1/blb/{id}/HTTPSlistener?listenerPort=N&clientToken=X
            $client->request('PUT', $path, $body, [
                'listenerPort' => $port,
                'clientToken' => $this->clientToken(),
            ]);
        });
    }

    /**
     * 更新 SSL 监听器证书（SSL 监听无 SNI 概念，直接设主证书）。
     *
     * @param  array<string,mixed>  $credentials
     */
    private function updateSslListener(array $credentials, string $region, string $loadbalancerId, int $port, string $certId): void
    {
        $this->guardSdk(function () use ($credentials, $region, $loadbalancerId, $port, $certId) {
            /** @var BaiduRestClient $client */
            $client = $this->makeClient('blb', $credentials, $region);
            // PUT /v1/blb/{id}/SSLlistener?listenerPort=N&clientToken=X  body {listenerPort, certIds:[certId]}
            $client->request('PUT', $this->blbUriPrefix()."/$loadbalancerId/SSLlistener", [
                'listenerPort' => $port,
                'certIds' => [$certId],
            ], [
                'listenerPort' => $port,
                'clientToken' => $this->clientToken(),
            ]);
        });
    }

    /**
     * 更新 HTTPS 监听时附加的 body 字段。BLB 无需附加，AppBLB override 回填 scheduler。
     *
     * @return array<string,mixed>
     */
    protected function httpsUpdateExtra(object $existingListener): array
    {
        return [];
    }

    /** 幂等令牌（对齐 certimate security.RandomString(32)）。 */
    private function clientToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 把可能为 null / 非数组的 JSON 值规整为数组（of stdClass）。
     *
     * @return list<object>
     */
    private function asArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_object'));
    }

    /**
     * 把 JSON 数组规整为字符串 list（certIds 回填用）。
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }
}
