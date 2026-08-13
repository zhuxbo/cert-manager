<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

trait MatchesJdcloudCertificateDomains
{
    protected function certificateMatches(string $certPem, string $domain): bool
    {
        $parsed = @openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            return false;
        }
        preg_match_all('/DNS:([^,\s]+)/', (string) ($parsed['extensions']['subjectAltName'] ?? ''), $matches);
        $names = $matches[1];
        if ($names === [] && is_string($parsed['subject']['CN'] ?? null)) {
            $names[] = $parsed['subject']['CN'];
        }
        foreach ($names as $name) {
            if (strcasecmp($name, $domain) === 0) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr(strtolower($name), 2);
                $lower = strtolower($domain);
                if (str_ends_with($lower, '.'.$suffix) && ! str_contains(substr($lower, 0, -strlen('.'.$suffix)), '.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
