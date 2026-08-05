<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanel;

/**
 * 宝塔面板 deployer 共用的 makeClient 实现（site / console 两 product 一致）。
 *
 * base_uri 取 server_url（加尾斜杠），allow_insecure 控制 TLS 校验。签名由 BaotapanelClient 内部按
 * 每次请求即时计算（request_time + request_token 表单字段）。
 */
trait BuildsBaotapanelClient
{
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new BaotapanelClient(
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
