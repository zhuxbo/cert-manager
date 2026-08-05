<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanelgo;

/**
 * 宝塔面板（Windows Go 版）deployer 共用的 makeClient 实现（site / console 两 product 一致）。
 *
 * base_uri 取 server_url（加尾斜杠），allow_insecure 控制 TLS 校验。签名由 BaotapanelgoClient 内部
 * 按每次请求即时计算。
 */
trait BuildsBaotapanelgoClient
{
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new BaotapanelgoClient(
                $this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/').'/', [
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure'] ?? null),
                ]),
                (string) ($credentials['api_key'] ?? ''),
            ),
        };
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }
}
