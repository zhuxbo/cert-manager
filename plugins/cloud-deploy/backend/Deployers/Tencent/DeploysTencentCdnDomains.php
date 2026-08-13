<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use TencentCloud\Cdn\V20180606\CdnClient;
use TencentCloud\Cdn\V20180606\Models\DescribeCertDomainsRequest;
use TencentCloud\Cdn\V20180606\Models\DescribeDomainsConfigRequest;
use TencentCloud\Cdn\V20180606\Models\DescribeDomainsRequest;
use TencentCloud\Cdn\V20180606\Models\Https;
use TencentCloud\Cdn\V20180606\Models\ServerCert;
use TencentCloud\Cdn\V20180606\Models\UpdateDomainConfigRequest;

/** CDN/ECDN 共用的 exact/wildcard/certsan 域名选择与证书更新语义。 */
trait DeploysTencentCdnDomains
{
    /** @param array<string,mixed> $credentials @param array<string,mixed> $config */
    protected function deployTencentCdnDomains(string $certificateId, array $credentials, array $config, string $product): void
    {
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        $domain = in_array($pattern, ['', 'exact', 'wildcard'], true)
            ? (string) $this->requireConfig($config, 'domain')
            : (string) ($config['domain'] ?? '');

        /** @var CdnClient $client */
        $client = $this->makeClient('cdn', $credentials);
        $domains = match ($pattern) {
            '', 'exact' => [$domain],
            'wildcard' => str_starts_with($domain, '*.')
                ? $this->findTencentCdnWildcardDomains($client, $domain, $product)
                : [$domain],
            'certsan' => $this->findTencentCdnCertDomains($client, $certificateId, $product),
            default => $this->fail("不支持的域名匹配模式: $pattern"),
        };

        foreach (array_values(array_unique($domains)) as $matchedDomain) {
            $this->updateTencentCdnDomain($client, $matchedDomain, $certificateId, $credentials);
        }
    }

    /** @return list<string> */
    private function findTencentCdnWildcardDomains(CdnClient $client, string $wildcard, string $product): array
    {
        $offset = 0;
        $limit = 100;
        $domains = [];
        do {
            $response = $this->guardSdk(function () use ($client, $wildcard, $offset, $limit) {
                $request = new DescribeDomainsRequest;
                $request->deserialize([
                    'Filters' => [[
                        'Name' => 'domain',
                        'Value' => [substr($wildcard, 2)],
                        'Fuzzy' => true,
                    ]],
                    'Offset' => $offset,
                    'Limit' => $limit,
                ]);

                return $client->DescribeDomains($request);
            });
            $items = $response->getDomains();
            foreach ($items as $item) {
                $name = (string) $item->getDomain();
                if ((string) $item->getProduct() === $product && $this->tencentWildcardMatches($wildcard, $name)) {
                    $domains[] = $name;
                }
            }
            $offset += $limit;
        } while (count($items) === $limit);

        return $domains;
    }

    /** @return list<string> */
    private function findTencentCdnCertDomains(CdnClient $client, string $certificateId, string $product): array
    {
        return $this->guardSdk(function () use ($client, $certificateId, $product) {
            $request = new DescribeCertDomainsRequest;
            $request->deserialize(['CertId' => $certificateId, 'Product' => $product]);

            return array_values(array_map('strval', $client->DescribeCertDomains($request)->getDomains()));
        });
    }

    /** @param array<string,mixed> $credentials */
    private function updateTencentCdnDomain(CdnClient $client, string $domain, string $certificateId, array $credentials): void
    {
        $this->guardSdk(function () use ($client, $domain, $certificateId, $credentials) {
            $describe = new DescribeDomainsConfigRequest;
            $describe->deserialize([
                'Filters' => [['Name' => 'domain', 'Value' => [$domain]]],
                'Offset' => 0,
                'Limit' => 1,
            ]);
            $domains = $client->DescribeDomainsConfig($describe)->getDomains();
            if ($domains === []) {
                $this->fail("未找到域名: $domain");
            }

            $https = $domains[0]->getHttps();
            if ($https?->getCertInfo()?->getCertId() === $certificateId) {
                return;
            }
            if (! $https instanceof Https) {
                $https = new Https;
                $https->deserialize(['Switch' => 'on']);
            } else {
                unset($https->SslStatus);
            }
            $cert = new ServerCert;
            $cert->deserialize(['CertId' => $certificateId]);
            $https->setCertInfo($cert);

            $update = new UpdateDomainConfigRequest;
            $payload = ['Domain' => $domain];
            if (isset($credentials['project_id']) && is_numeric($credentials['project_id'])) {
                $payload['ProjectId'] = (int) $credentials['project_id'];
            }
            $update->deserialize($payload);
            $update->setHttps($https);
            $client->UpdateDomainConfig($update);
        });
    }

    private function tencentWildcardMatches(string $wildcard, string $domain): bool
    {
        $suffix = substr(strtolower($wildcard), 2);
        $domain = strtolower($domain);

        return str_ends_with($domain, '.'.$suffix)
            && substr_count(substr($domain, 0, -strlen('.'.$suffix)), '.') === 0;
    }
}
