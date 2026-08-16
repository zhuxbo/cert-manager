<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use TencentCloud\Common\Profile\HttpProfile;

trait UsesTencentEndpoint
{
    /** @param array<string,mixed> $credentials @param array<string,mixed> $config */
    protected function withTencentEndpoint(array $credentials, array $config): array
    {
        if (is_string($config['endpoint'] ?? null) && trim($config['endpoint']) !== '') {
            $credentials['endpoint'] = trim($config['endpoint']);
        }

        return $credentials;
    }

    /** @param array<string,mixed> $credentials */
    protected function configureTencentEndpoint(HttpProfile $http, array $credentials, string $kind): void
    {
        $configured = is_string($credentials['endpoint'] ?? null) ? trim($credentials['endpoint']) : '';
        if ($configured === '') {
            return;
        }
        $candidate = str_contains($configured, '://') ? $configured : 'https://'.$configured;
        $parts = parse_url($candidate);
        if (! is_array($parts) || ! empty($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new OutboundDestinationException('endpoint_not_allowed');
        }
        $destination = app(OutboundDestinationPolicy::class)->authorize('tencent', $candidate);

        if ($kind === 'ssl') {
            if (! str_ends_with($destination->host, '.intl.tencentcloudapi.com')) {
                return;
            }
            app(OutboundDestinationPolicy::class)->authorizeOfficialHost('tencent', 'ssl.intl.tencentcloudapi.com');
            $http->setEndpoint('ssl.intl.tencentcloudapi.com');

            return;
        }

        $host = str_contains($destination->host, ':') ? '['.$destination->host.']' : $destination->host;
        $defaultPort = $destination->scheme === 'https' ? 443 : 80;
        $http->setEndpoint($host.($destination->port === $defaultPort ? '' : ':'.$destination->port));
    }
}
