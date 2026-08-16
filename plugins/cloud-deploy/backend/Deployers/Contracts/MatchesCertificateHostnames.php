<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

trait MatchesCertificateHostnames
{
    protected function certificateMatchesHostname(string $certificate, string $hostname): bool
    {
        $parsed = @openssl_x509_parse($certificate);
        if (! is_array($parsed)) {
            return false;
        }
        $names = [];
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);
                if (str_starts_with(strtoupper($entry), 'DNS:')) {
                    $names[] = trim(substr($entry, 4));
                }
            }
        }
        if ($names === []) {
            $cn = $parsed['subject']['CN'] ?? '';
            if (is_string($cn) && $cn !== '') {
                $names[] = $cn;
            }
        }
        foreach ($names as $name) {
            if ($this->certificateHostnamePatternMatches($name, $hostname)) {
                return true;
            }
        }

        return false;
    }

    protected function certificateHostnamePatternMatches(string $pattern, string $hostname): bool
    {
        if (strcasecmp($pattern, $hostname) === 0) {
            return true;
        }
        if (! str_starts_with($pattern, '*.')) {
            return false;
        }
        $suffix = substr($pattern, 2);
        if (! str_ends_with(strtolower($hostname), '.'.strtolower($suffix))) {
            return false;
        }
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return $prefix !== '' && ! str_contains($prefix, '.');
    }
}
