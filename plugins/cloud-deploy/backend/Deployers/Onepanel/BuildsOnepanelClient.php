<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

use GuzzleHttp\Client as GuzzleClient;

/**
 * 1Panel deployer 共用的 makeClient 实现（site / console 两 product 一致）。
 *
 * 按凭证 api_version 决定 base 路径（/api/v1 或 /api/v2）+ 是否 v2；node_name 仅 v2 生效；
 * allow_insecure 控制 TLS 校验。OnepanelClient 内部按 isV2 切签名头（CurrentNode）/ console 路径。
 */
trait BuildsOnepanelClient
{
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => $this->buildOnepanelClient($credentials),
        };
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    private function buildOnepanelClient(array $credentials): OnepanelClient
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');
        $apiVersion = strtolower((string) ($credentials['api_version'] ?? 'v1'));
        $isV2 = $apiVersion === 'v2';
        $base = $serverUrl.($isV2 ? '/api/v2/' : '/api/v1/');

        $guzzle = new GuzzleClient([
            'base_uri' => $base,
            'timeout' => 30,
            'verify' => ! $this->truthy($credentials['allow_insecure'] ?? null),
        ]);

        return new OnepanelClient(
            $guzzle,
            (string) ($credentials['api_key'] ?? ''),
            $isV2,
            (string) ($credentials['node_name'] ?? ''),
        );
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }
}
