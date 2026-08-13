<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

trait MatchesBytePlusDomains
{
    protected function hostnameMatches(string $pattern, string $hostname): bool
    {
        $pattern = strtolower(rtrim(trim($pattern), '.'));
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if (! str_starts_with($pattern, '*.')) {
            return $pattern === $hostname;
        }
        $suffix = substr($pattern, 2);
        if ($suffix === '' || ! str_ends_with($hostname, '.'.$suffix)) {
            return false;
        }
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return $prefix !== '' && ! str_contains($prefix, '.');
    }
}
