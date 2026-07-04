<?php

namespace Plugins\CloudDeploy\Deployers\Ratpanel;

use GuzzleHttp\Client as GuzzleClient;

/**
 * 耗子面板 makeClient 共用逻辑（site / console 两端点一致）。
 *
 * base_uri = {serverUrl}/api/；签名用 basePath = serverUrl 自身 path + "/api"（兼容 serverUrl 带子路径）。
 * allow_insecure 凭证为真时关 Guzzle TLS 校验。使用方需 use AbstractDeployer。
 */
trait BuildsRatpanelClient
{
    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeRatpanelClient(array $credentials): RatpanelRestClient
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');
        // 签名 canonicalPath 需要 serverUrl 自身的 path 前缀（如 https://host/panel → /panel/api）
        $urlPath = (string) (parse_url($serverUrl, PHP_URL_PATH) ?: '');
        $basePath = rtrim($urlPath, '/').'/api';

        return new RatpanelRestClient(
            new GuzzleClient([
                'base_uri' => "$serverUrl/api/",
                'timeout' => 30,
                'verify' => empty($credentials['allow_insecure']),
                'headers' => ['Accept' => 'application/json'],
            ]),
            $basePath,
            (int) ($credentials['access_token_id'] ?? 0),
            (string) ($credentials['access_token'] ?? ''),
        );
    }
}
