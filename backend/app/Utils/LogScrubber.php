<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * 日志脱敏器（集中实现，静态方法）
 *
 * 替代原 App\Traits\LogSanitizer：从 trait 改为独立类后，
 * - 调用面只需 use 一行 + 静态调（无需把 trait 嵌入每个调用方类）
 * - 支持 config('logs.scrubber.extra_*') / 环境变量在不改代码的前提下扩展敏感字段
 */
class LogScrubber
{
    /**
     * 内置敏感字段（精确匹配，大小写不敏感）
     *
     * @var array<int, string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'auth_password',
        'auth_token',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'bearer_token',
        'authorization',
        'csr',
        'private_key',
        'cert',
        'intermediate_cert',
        'pass',
        'secret',
        'key',
        'api_key',
        'client_secret',
        'session_id',
        'csrf_token',
        'direct_login_url',
        'document_content',
        // ACME EAB HMAC（外部账户绑定密钥，明文不应进日志）
        'eab_hmac',
        // 业务敏感字段
        'bank_account',
        'bank_card',
        'id_card',
        'national_id',
        'private',
        'cert_pem',
        'credit',
    ];

    /**
     * 内置敏感字段正则（模式匹配，扫描包含的所有字段名）
     *
     * @var array<int, string>
     */
    private const SENSITIVE_PATTERNS = [
        '/.*token.*/i',
        '/.*password.*/i',
        '/.*secret.*/i',
        '/.*key.*/i',
        '/.*auth.*/i',
        '/.*credit.*/i',
        '/.*pem.*/i',
    ];

    /**
     * 脱敏数组：递归替换敏感字段
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key) && ! self::isEmptyValue($value)) {
                $data[$key] = '******';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    /**
     * 脱敏响应内容：兼容 JSON 字符串 / 数组 / null / 标量
     *
     * - 空值（null / '' / [] / 0 / false）返回 null
     * - JSON 字符串解码后递归脱敏
     * - 非数组非字符串包装为 ['content' => (string) $response]
     */
    public static function scrubResponse(mixed $response): ?array
    {
        if (self::isEmptyValue($response)) {
            return null;
        }

        if (is_string($response) && json_validate($response)) {
            $response = json_decode($response, true);
        }

        if (! is_array($response)) {
            $response = ['content' => is_scalar($response) ? (string) $response : json_encode($response, JSON_UNESCAPED_UNICODE)];
        }

        return self::scrub($response);
    }

    /**
     * 判断字段名是否敏感（精确匹配 + 正则匹配）
     */
    public static function isSensitive(string $field): bool
    {
        $lower = strtolower($field);

        foreach (self::fields() as $sensitive) {
            if ($lower === strtolower($sensitive)) {
                return true;
            }
        }

        foreach (self::patterns() as $pattern) {
            if (@preg_match($pattern, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 合并内置 + config 扩展敏感字段
     *
     * @return array<int, string>
     */
    private static function fields(): array
    {
        $extra = (array) config('logs.scrubber.extra_fields', []);
        $extra = array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', $extra), static fn ($v) => $v !== '');

        return array_values(array_unique(array_merge(self::SENSITIVE_FIELDS, $extra)));
    }

    /**
     * 合并内置 + config 扩展敏感正则
     *
     * @return array<int, string>
     */
    private static function patterns(): array
    {
        $extra = (array) config('logs.scrubber.extra_patterns', []);
        $extra = array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', $extra), static fn ($v) => $v !== '');

        return array_values(array_unique(array_merge(self::SENSITIVE_PATTERNS, $extra)));
    }

    /**
     * 判断是否为"空值"（null / '' / 空数组 / 0 / false）
     *
     * 用 empty() 语义保持与原 LogSanitizer 行为兼容：
     * password='' 不被替换为 ******（避免覆写空字段）。
     */
    private static function isEmptyValue(mixed $value): bool
    {
        return empty($value);
    }
}
