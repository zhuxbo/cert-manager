<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

/**
 * 证书主机名匹配（对齐 certimate pkg/utils/cert/hostname IsMatchByCertificate 语义）。
 *
 * 检查目标 hostname 是否被证书覆盖：遍历证书 SAN（DNS）+ CN，逐个按
 *   - 精确匹配（大小写不敏感），或
 *   - 通配符单标签匹配（pattern "*.example.com" 命中 "sub.example.com"，不命中 "a.b.example.com" 与
 *     "example.com"）
 * 判定。1Panel CERTSAN 部署模式据此筛选证书覆盖的网站。
 */
trait MatchesCertificateHostname
{
    /**
     * 给定证书 PEM 与目标 hostname，判断是否匹配。解析失败返回 false。
     */
    protected function certMatchesHostname(string $certPEM, string $hostname): bool
    {
        if ($certPEM === '' || $hostname === '') {
            return false;
        }
        $parsed = @openssl_x509_parse($certPEM);
        if (! is_array($parsed)) {
            return false;
        }

        foreach ($this->certHostPatterns($parsed) as $pattern) {
            if ($this->hostMatchesPattern($pattern, $hostname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从解析后的证书取主机名模式集（SAN DNS 优先；无 SAN 时回落 CN，与 x509 VerifyHostname 一致）。
     *
     * @param  array<string,mixed>  $parsed
     * @return list<string>
     */
    private function certHostPatterns(array $parsed): array
    {
        $patterns = [];
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);
                if (stripos($entry, 'DNS:') === 0) {
                    $name = trim(substr($entry, 4));
                    if ($name !== '') {
                        $patterns[] = $name;
                    }
                }
            }
        }
        if ($patterns === []) {
            $cn = $parsed['subject']['CN'] ?? '';
            if (is_string($cn) && $cn !== '') {
                $patterns[] = $cn;
            }
        }

        return $patterns;
    }

    /**
     * 单个模式匹配（精确 / 通配符单标签）。
     */
    private function hostMatchesPattern(string $pattern, string $hostname): bool
    {
        if (strcasecmp($pattern, $hostname) === 0) {
            return true;
        }
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1); // ".example.com"
            // 通配符仅匹配一个标签：去掉 hostname 第一个标签后须等于 suffix
            $dot = strpos($hostname, '.');
            if ($dot === false) {
                return false;
            }
            $rest = substr($hostname, $dot); // ".sub..." 形式
            // hostname 第一个标签非空（防 ".example.com" 误判）
            if ($dot === 0) {
                return false;
            }

            return strcasecmp($suffix, $rest) === 0;
        }

        return false;
    }
}
