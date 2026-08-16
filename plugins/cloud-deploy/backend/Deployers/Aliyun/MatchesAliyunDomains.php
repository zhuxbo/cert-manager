<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

trait MatchesAliyunDomains
{
    protected function casRegion(array $config): string
    {
        return (string) ($config['region'] ?? '');
    }

    protected function casEndpoint(array $credentials): string
    {
        $region = (string) ($credentials['region'] ?? '');

        return $region === '' || $region === 'cn-hangzhou' ? 'cas.aliyuncs.com' : "cas.$region.aliyuncs.com";
    }

    protected function hostnameMatches(string $pattern, string $hostname): bool
    {
        $pattern = strtolower(rtrim(trim($pattern), '.'));
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if (! str_starts_with($pattern, '*.')) {
            return $pattern === $hostname;
        }

        $suffix = substr($pattern, 2);
        if ($suffix === '') {
            return false;
        }

        // 对齐 Certimate hostname.IsMatch：候选以 "." 开头代表等价泛域名（*.example.com）。
        // 该分支仅比较同一后缀，不会把 *.example.com 扩大成多层子域匹配。
        if (str_starts_with($hostname, '.')) {
            return $hostname === '.'.$suffix;
        }
        if (! str_ends_with($hostname, '.'.$suffix)) {
            return false;
        }

        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return $prefix !== '' && ! str_contains($prefix, '.');
    }

    protected function certificateMatches(string $certPem, string $hostname): bool
    {
        $parsed = @openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            return false;
        }

        $names = [];
        $subjectAltName = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($subjectAltName)) {
            foreach (explode(',', $subjectAltName) as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, 'DNS:')) {
                    $names[] = substr($entry, 4);
                }
            }
        }
        if ($names === []) {
            $commonName = $parsed['subject']['CN'] ?? '';
            if (is_string($commonName) && $commonName !== '') {
                $names[] = $commonName;
            }
        }

        foreach ($names as $name) {
            if ($this->hostnameMatches($name, $hostname)) {
                return true;
            }
        }

        return false;
    }
}
