<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use TencentCloud\Ssl\V20191205\Models\DescribeCertificateRequest;
use TencentCloud\Ssl\V20191205\SslClient;

trait MatchesTencentCertificateDomains
{
    /** @param list<string> $candidates @return list<string> */
    protected function matchTencentCertificateDomains(string $certificateId, array $credentials, array $candidates): array
    {
        /** @var SslClient $ssl */
        $ssl = $this->makeClient('ssl', $credentials);
        $names = $this->guardSdk(function () use ($ssl, $certificateId): array {
            $request = new DescribeCertificateRequest;
            $request->deserialize(['CertificateId' => $certificateId]);

            return array_values(array_map('strval', $ssl->DescribeCertificate($request)->getSubjectAltName()));
        });

        return array_values(array_filter($candidates, function (string $domain) use ($names): bool {
            foreach ($names as $name) {
                if (strcasecmp($name, $domain) === 0) {
                    return true;
                }
                if (str_starts_with($name, '*.') && $this->tencentHostnameMatches($name, $domain)) {
                    return true;
                }
            }

            return false;
        }));
    }

    protected function tencentHostnameMatches(string $wildcard, string $domain): bool
    {
        $suffix = substr(strtolower($wildcard), 2);
        $domain = strtolower($domain);

        return str_ends_with($domain, '.'.$suffix) && ! str_contains(substr($domain, 0, -strlen('.'.$suffix)), '.');
    }
}
