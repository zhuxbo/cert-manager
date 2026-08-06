<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

final class OutboundIpClassifier
{
    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    public const FORBIDDEN = 'forbidden';

    private const PRIVATE_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ];

    private const FORBIDDEN_CIDRS = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '100::/64',
        '2001::/32',
        '2001:2::/48',
        '2001:10::/28',
        '2001:db8::/32',
        'fe80::/10',
        'ff00::/8',
    ];

    public function classify(string $ip): string
    {
        $ip = $this->normalizeMappedIpv4($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return self::FORBIDDEN;
        }

        foreach (self::PRIVATE_CIDRS as $cidr) {
            if ($this->contains($ip, $cidr)) {
                return self::PRIVATE;
            }
        }

        foreach (self::FORBIDDEN_CIDRS as $cidr) {
            if ($this->contains($ip, $cidr)) {
                return self::FORBIDDEN;
            }
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        return filter_var($ip, FILTER_VALIDATE_IP, $flags) === false
            ? self::FORBIDDEN
            : self::PUBLIC;
    }

    public function normalizeMappedIpv4(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        if (substr($packed, 0, 10) === str_repeat("\0", 10) && substr($packed, 10, 2) === "\xff\xff") {
            return (string) inet_ntop(substr($packed, 12, 4));
        }

        return (string) inet_ntop($packed);
    }

    private function contains(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int) $prefix;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
