<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;

trait MatchesVolcDomains
{
    use MatchesCertificateHostnames;

    /** @return array{0:string,1:string} remote id, leaf PEM */
    protected function volcCertificateReference(string|array $certRef): array
    {
        if (is_string($certRef)) {
            return [$certRef, ''];
        }

        return [(string) ($certRef['remote_cert_id'] ?? ''), (string) ($certRef['cert'] ?? '')];
    }

    /** @param list<string> $candidates @return list<string> */
    protected function matchVolcDomains(array $candidates, string $pattern, string $domain, string $certificate): array
    {
        return match ($pattern) {
            '', 'exact' => $domain === '' ? $this->fail('缺少配置 domain') : [$domain],
            'wildcard' => $domain === ''
                ? $this->fail('缺少配置 domain')
                : (str_starts_with($domain, '*.')
                    ? array_values(array_filter($candidates, fn (string $candidate): bool => $this->certificateHostnamePatternMatches($domain, $candidate)))
                    : [$domain]),
            'certsan' => $certificate === ''
                ? $this->fail('certsan 匹配缺少证书材料')
                : array_values(array_filter($candidates, fn (string $candidate): bool => $this->certificateMatchesHostname($certificate, $candidate))),
            default => $this->fail("不支持的域名匹配模式 $pattern"),
        };
    }
}
