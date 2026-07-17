<?php

namespace App\Services\Plugin;

final class PluginOutputSanitizer
{
    public static function sanitize(string $output, int $limit = 4000): string
    {
        $patterns = [
            '#(https?://)([^\\s/@:]+):([^\\s/@]+)@#i' => '$1$2:******@',
            '/([?&](?:access[_-]?token|api[_-]?key|auth|key|passwd|password|secret|token)=)[^\\s&"\']+/i' => '$1******',
            '/(\\bAuthorization\\s*:\\s*(?:Bearer|Basic)\\s+)[^\\s,;]+/i' => '$1******',
            '/\\b((?:COMPOSER_AUTH|GITHUB_TOKEN|GITLAB_TOKEN|TOKEN|PASSWORD|SECRET|API_KEY)=)[^\\s]+/i' => '$1******',
            '/("github-oauth"\\s*:\\s*)\\{[^\\r\\n}]+\\}/i' => '$1{"******":"******"}',
            '/("gitlab-token"\\s*:\\s*)\\{[^\\r\\n}]+\\}/i' => '$1{"******":"******"}',
            '/("http-basic"\\s*:\\s*)\\{[^\\r\\n}]+\\}/i' => '$1{"******":{"username":"******","password":"******"}}',
            '/(["\'](?:access[_-]?token|api[_-]?key|auth|key|passwd|password|secret|token)["\']\\s*:\\s*)["\'][^"\']+["\']/i' => '$1"******"',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $output = preg_replace($pattern, $replacement, $output) ?? $output;
        }

        return mb_substr($output, 0, $limit);
    }
}
