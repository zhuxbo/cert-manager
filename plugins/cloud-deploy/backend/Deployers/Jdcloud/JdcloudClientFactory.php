<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

/**
 * 京东云各服务 JdcloudRestClient 构造工厂（集中 endpoint / serviceName / revision，对齐 certimate
 * jdcloud-sdk-go 各 service client 的 SetEndpoint / ServiceName / Revision 声明）。
 *
 * serviceName 进签名 credential scope（HMAC 链一环），写错即全部 403；故集中一处、与 certimate 逐一对齐：
 *   - ssl  : ssl.jdcloud-api.com  / ssl  / 1.0.2
 *   - lb   : lb.jdcloud-api.com   / lb   / 0.6.6   （alb 端点用）
 *   - cdn  : cdn.jdcloud-api.com  / cdn  / 0.10.48
 *   - live : live.jdcloud-api.com / live / 1.0.22
 *   - vod  : vod.jdcloud-api.com  / vod  / 1.2.1
 *   - waf  : waf.jdcloud-api.com  / waf  / 1.0.9
 *
 * revision 仅进 User-Agent（不参与签名），但一并对齐以贴近 certimate 线协议。
 *
 * @param  array{access_key_id?:string,access_key_secret?:string}  $credentials
 */
class JdcloudClientFactory
{
    /** @param array<string,mixed> $credentials */
    public static function ssl(array $credentials): JdcloudRestClient
    {
        return new JdcloudRestClient('ssl.jdcloud-api.com', 'ssl', '1.0.2', $credentials);
    }

    /** @param array<string,mixed> $credentials */
    public static function lb(array $credentials): JdcloudRestClient
    {
        return new JdcloudRestClient('lb.jdcloud-api.com', 'lb', '0.6.6', $credentials);
    }

    /** @param array<string,mixed> $credentials */
    public static function cdn(array $credentials): JdcloudRestClient
    {
        return new JdcloudRestClient('cdn.jdcloud-api.com', 'cdn', '0.10.48', $credentials);
    }

    /** @param array<string,mixed> $credentials */
    public static function live(array $credentials): JdcloudRestClient
    {
        return new JdcloudRestClient('live.jdcloud-api.com', 'live', '1.0.22', $credentials);
    }

    /** @param array<string,mixed> $credentials */
    public static function vod(array $credentials): JdcloudRestClient
    {
        return new JdcloudRestClient('vod.jdcloud-api.com', 'vod', '1.2.1', $credentials);
    }

    /** @param array<string,mixed> $credentials */
    public static function waf(array $credentials): JdcloudRestClient
    {
        return new JdcloudRestClient('waf.jdcloud-api.com', 'waf', '1.0.9', $credentials);
    }
}
