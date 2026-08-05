<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

use GuzzleHttp\Client;

final class SafeHttpClientFactory
{
    public function __construct(private readonly OutboundDestinationPolicy $policy) {}

    /** @param array<string,mixed> $options */
    public function forBaseUri(string $provider, string $baseUri, array $options = []): Client
    {
        $destination = $this->policy->authorize($provider, $baseUri);
        $options['base_uri'] = $destination->url;

        return new Client($this->secureOptions($options, $destination));
    }

    /** @param array<string,mixed> $options */
    public function forAbsoluteUrl(string $provider, string $url, array $options = []): Client
    {
        $destination = $this->policy->authorize($provider, $url);

        return new Client($this->secureOptions($options, $destination));
    }

    /**
     * 为复用 Guzzle 传输层的 SDK 生成与安全客户端相同的连接约束。
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function optionsFor(OutboundDestination $destination, array $options = []): array
    {
        return $this->secureOptions($options, $destination);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function secureOptions(array $options, OutboundDestination $destination): array
    {
        $options['allow_redirects'] = false;
        $options['proxy'] = null;

        $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
        foreach ([
            'CURLOPT_PROXY',
            'CURLOPT_PRE_PROXY',
            'CURLOPT_CONNECT_TO',
            'CURLOPT_UNIX_SOCKET_PATH',
        ] as $optionName) {
            if (defined($optionName)) {
                unset($curl[constant($optionName)]);
            }
        }

        if (filter_var($destination->host, FILTER_VALIDATE_IP) === false) {
            $addresses = array_map(
                static fn (string $address): string => str_contains($address, ':') ? "[$address]" : $address,
                $destination->addresses,
            );
            $curl[CURLOPT_RESOLVE] = [
                $destination->host.':'.$destination->port.':'.implode(',', $addresses),
            ];
        } else {
            unset($curl[CURLOPT_RESOLVE]);
        }
        $options['curl'] = $curl;

        return $options;
    }
}
