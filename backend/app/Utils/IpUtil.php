<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * IP 段判定工具（SSRF 白名单制防护）
 *
 * 显式枚举私网/保留段，不依赖 FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE
 * 黑名单过滤（CGNAT 100.64/10、多播 224/4、benchmark 198.18/15 等
 * 不在两个 flag 覆盖内，会被静默放行）。
 */
class IpUtil
{
    /**
     * 禁止外联的 IPv4 私网/保留段（IANA special-purpose，均不可能是合法公网地址）
     */
    private const PRIVATE_OR_RESERVED_IPV4_CIDRS = [
        '0.0.0.0/8',        // "本网络"，含 0.0.0.0
        '10.0.0.0/8',       // RFC1918 私网
        '100.64.0.0/10',    // CGNAT（RFC6598）
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local，含云元数据 169.254.169.254
        '172.16.0.0/12',    // RFC1918 私网
        '192.0.0.0/24',     // IETF 协议保留
        '192.0.2.0/24',     // TEST-NET-1
        '192.168.0.0/16',   // RFC1918 私网
        '198.18.0.0/15',    // benchmark（RFC2544，fake-ip 代理 DNS 常用）
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // 多播
        '240.0.0.0/4',      // 保留，含广播 255.255.255.255
    ];

    /**
     * IP 是否属于私网/保留段（loopback、RFC1918、link-local、CGNAT、多播等）
     *
     * 仅真公网 IP 返回 false；非法 IP fail-closed 返回 true。
     */
    public static function isPrivateOrReserved(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::ipv4InAnyCidr($ip, self::PRIVATE_OR_RESERVED_IPV4_CIDRS);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::isPrivateOrReservedIpv6($ip);
        }

        return true;
    }

    private static function ipv4InAnyCidr(string $ip, array $cidrs): bool
    {
        $long = ip2long($ip);

        foreach ($cidrs as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateOrReservedIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        // IPv4-mapped（::ffff:a.b.c.d）解出内嵌 IPv4 按 IPv4 清单判
        if (substr($packed, 0, 12) === str_repeat("\x00", 10)."\xff\xff") {
            $embedded = inet_ntop(substr($packed, 12));

            return $embedded === false || self::isPrivateOrReserved($embedded);
        }

        // ::（unspecified）与 ::1（loopback）：前 15 字节全零
        if (substr($packed, 0, 15) === str_repeat("\x00", 15)) {
            return true;
        }

        $first = ord($packed[0]);

        return ($first & 0xFE) === 0xFC // fc00::/7 ULA
            || ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) // fe80::/10 link-local
            || $first === 0xFF // ff00::/8 多播
            || substr($packed, 0, 4) === "\x20\x01\x0d\xb8"; // 2001:db8::/32 文档保留
    }
}
