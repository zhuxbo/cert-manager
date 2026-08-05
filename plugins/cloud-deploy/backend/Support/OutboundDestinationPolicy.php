<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

use Closure;

final class OutboundDestinationPolicy
{
    private readonly Closure $resolver;

    /** @var array<string,true> */
    private readonly array $privateTargets;

    public function __construct(
        ?callable $resolver = null,
        ?array $privateTargets = null,
        private readonly OutboundIpClassifier $classifier = new OutboundIpClassifier,
    ) {
        $this->resolver = Closure::fromCallable($resolver ?? $this->resolve(...));
        $targets = $privateTargets ?? config('cloud-deploy.outbound.private_targets', []);
        $normalizedTargets = array_map(
            static fn (mixed $target): string => strtolower(trim((string) $target)),
            is_array($targets) ? $targets : [],
        );
        $this->privateTargets = array_fill_keys(array_filter($normalizedTargets), true);
    }

    public function authorize(string $provider, string $url): OutboundDestination
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new OutboundDestinationException('invalid_url');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundDestinationException('scheme_not_allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundDestinationException('userinfo_not_allowed');
        }
        if (isset($parts['fragment'])) {
            throw new OutboundDestinationException('fragment_not_allowed');
        }

        $host = $this->normalizeHost((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new OutboundDestinationException('invalid_url');
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $isIpLiteral ? [$host] : ($this->resolver)($host);
        $addresses = array_values(array_unique(array_map(
            fn (mixed $ip): string => $this->classifier->normalizeMappedIpv4(trim((string) $ip)),
            is_array($addresses) ? $addresses : [],
        )));
        if ($addresses === []) {
            throw new OutboundDestinationException('dns_resolution_failed');
        }

        $scopes = [];
        foreach ($addresses as $address) {
            $scope = $this->classifier->classify($address);
            if ($scope === OutboundIpClassifier::FORBIDDEN) {
                throw new OutboundDestinationException('forbidden_address');
            }
            $scopes[$scope] = true;
        }

        if (count($scopes) > 1) {
            throw new OutboundDestinationException('mixed_address_scope');
        }

        $scope = (string) array_key_first($scopes);
        if ($scope === OutboundIpClassifier::PRIVATE && ! $this->privateTargetAllowed($provider, $host, $port)) {
            throw new OutboundDestinationException('private_not_allowed');
        }

        return new OutboundDestination(
            $this->canonicalUrl($parts, $scheme, $host, $port),
            $scheme,
            $host,
            $port,
            $scope,
            $addresses,
        );
    }

    /**
     * 校验由租户字段拼接出的厂商官方主机名，再执行常规出站授权。
     *
     * 这里要求传入值本身就是完整 DNS 名，不能让 parse_url 把 `:port/path` 等片段
     * 重新解释为端口或路径，从而丢掉调用方追加的官方域名后缀。
     */
    public function authorizeOfficialHost(string $provider, string $host): OutboundDestination
    {
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host) !== 1) {
            throw new OutboundDestinationException('invalid_official_host');
        }

        return $this->authorize($provider, 'https://'.$host);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        if ($host === '') {
            return '';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->classifier->normalizeMappedIpv4($host);
        }

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            if (! function_exists('idn_to_ascii')) {
                throw new OutboundDestinationException('invalid_host');
            }
            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted === false) {
                throw new OutboundDestinationException('invalid_host');
            }
            $host = strtolower($converted);
        }

        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) !== 1) {
            throw new OutboundDestinationException('invalid_host');
        }

        return $host;
    }

    private function privateTargetAllowed(string $provider, string $host, int $port): bool
    {
        $allowlistHost = str_contains($host, ':') ? "[$host]" : $host;
        $key = strtolower(trim($provider)).'@'.$allowlistHost.':'.$port;

        return isset($this->privateTargets[$key]);
    }

    /** @param array<string,mixed> $parts */
    private function canonicalUrl(array $parts, string $scheme, string $host, int $port): string
    {
        $authorityHost = str_contains($host, ':') ? "[$host]" : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority = $authorityHost.($port === $defaultPort ? '' : ":$port");
        $path = (string) ($parts['path'] ?? '');
        $query = array_key_exists('query', $parts) ? '?'.(string) $parts['query'] : '';

        return "$scheme://$authority$path$query";
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
