<?php

namespace Plugins\CloudDeploy\Deployers\Baotawaf;

/**
 * 堡塔云 WAF deployer 共用的 makeClient 实现（site / console 两 product 一致）。
 *
 * base_uri 取 server_url + /api（加尾斜杠），allow_insecure 控制 TLS 校验。签名由 BaotawafClient 内部
 * 按每次请求即时计算（waf_request_time + waf_request_token 请求头）。
 */
trait BuildsBaotawafClient
{
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new BaotawafClient(
                $this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/').'/api/', [
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
