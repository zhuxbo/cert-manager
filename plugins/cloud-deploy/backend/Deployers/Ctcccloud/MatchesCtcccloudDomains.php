<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;

trait MatchesCtcccloudDomains
{
    use MatchesCertificateHostnames;

    protected function matchingDomains(
        CtcccloudRestClient $client,
        string $path,
        string $domain,
        string $pattern,
        string $productCode = '',
        bool $wrapped = true,
        string $certificate = '',
    ): array {
        if (! in_array($pattern, ['exact', 'wildcard', 'certsan'], true)) {
            $this->fail("CTCC Cloud 不支持的域名匹配模式: $pattern");
        }
        if ($pattern === 'exact' || ($pattern === 'wildcard' && ! str_starts_with($domain, '*.'))) {
            return [$domain];
        }

        $matched = [];
        for ($page = 1; ; $page++) {
            $query = ['page' => $page, 'page_size' => 100];
            if ($productCode !== '') {
                $query['product_code'] = $productCode;
            }
            $response = $client->get($path, $query);
            $items = $wrapped
                ? ($response['returnObj']['result'] ?? [])
                : ($response['result'] ?? []);
            $items = is_array($items) ? $items : [];
            foreach ($items as $item) {
                if (! is_array($item) || in_array((int) ($item['status'] ?? 0), [1, 5, 6, 7, 8, 9, 11, 12], true)) {
                    continue;
                }
                $candidate = (string) ($item['domain'] ?? '');
                if (($pattern === 'wildcard' && $this->hostnameMatches($domain, $candidate))
                    || ($pattern === 'certsan' && $this->certificateMatchesHostname($certificate, $candidate))) {
                    $matched[] = $candidate;
                }
            }
            if (count($items) < 100) {
                break;
            }
        }
        if ($matched === []) {
            $this->fail('未找到匹配的天翼云域名');
        }

        return $matched;
    }

    private function hostnameMatches(string $pattern, string $hostname): bool
    {
        $pattern = strtolower(rtrim(trim($pattern), '.'));
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $suffix = str_starts_with($pattern, '*.') ? substr($pattern, 2) : $pattern;
        if (! str_starts_with($pattern, '*.')) {
            return $pattern === $hostname;
        }
        if ($suffix === '' || ! str_ends_with($hostname, '.'.$suffix)) {
            return false;
        }
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return $prefix !== '' && ! str_contains($prefix, '.');
    }
}
