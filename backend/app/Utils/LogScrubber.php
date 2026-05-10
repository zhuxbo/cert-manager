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
        // hmac：部分 ACME CA 创建账号响应以 credentials.hmac 字段返回 EAB 密钥
        'hmac',
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
     * 嵌套敏感字段（parent.child 形式）
     *
     * 用于字段名过于通用（如 path）无法全局过滤、仅在特定父级下才视为敏感的场景。
     * 匹配规则：当前路径等于该值，或以 `.{nested}` 为后缀。
     *
     * @var array<int, string>
     */
    private const SENSITIVE_NESTED_FIELDS = [
        // 部分 ACME CA 响应中 certificate.path 嵌套字段是证书 PEM 数组（顶层 path 仍是普通业务字段，需路径感知避免误伤）
        'certificate.path',
    ];

    /**
     * 脱敏数组：递归替换敏感字段
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public static function scrub(array $data): array
    {
        return self::scrubInternal($data, '');
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

        return self::scrubInternal($response, '');
    }

    /**
     * 使用自定义字段集脱敏（直对接异构 CA SDK 场景；预留扩展位）
     *
     * 与 scrub/scrubResponse 不同：完全使用传入的 $fields/$patterns，不叠加内置默认字段。
     * 用于上游响应只关心特殊字段（如 csr_code / X509Cert / certificate 等字符串叶子值），
     * 而内置 SENSITIVE_FIELDS 中"key/cert"等过于通用的字段名可能误命中上游业务字段。
     *
     * 当前 manager 通过统一接口调用上游（响应已在上游侧脱敏），不直接对接异构 CA；保留方法供未来扩展使用。
     *
     * @param  array<int, string>  $fields  精确匹配字段（大小写不敏感）
     * @param  array<int, string>  $patterns  正则字段
     */
    public static function scrubWith(mixed $response, array $fields, array $patterns = []): ?array
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

        return self::scrubInternalWith($response, $fields, $patterns);
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
     * 递归脱敏（内部）：跟踪 parentPath 以匹配嵌套敏感字段
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private static function scrubInternal(array $data, string $parentPath): array
    {
        foreach ($data as $key => $value) {
            $path = $parentPath === '' ? (string) $key : "$parentPath.$key";

            if (is_string($key) && self::isSensitiveAtPath($key, $path) && ! self::isEmptyValue($value)) {
                $data[$key] = '******';
            } elseif (is_array($value)) {
                $data[$key] = self::scrubInternal($value, $path);
            }
        }

        return $data;
    }

    /**
     * 字段名 + 路径联合判断
     */
    private static function isSensitiveAtPath(string $field, string $path): bool
    {
        if (self::isSensitive($field)) {
            return true;
        }

        if ($path === '') {
            return false;
        }

        $lowerPath = strtolower($path);
        foreach (self::nestedFields() as $nested) {
            $nested = strtolower($nested);
            if ($lowerPath === $nested || str_ends_with($lowerPath, '.'.$nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * scrubWith 的递归实现：使用传入字段集，不读 config / 内置默认
     *
     * @param  array<string|int, mixed>  $data
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $patterns
     * @return array<string|int, mixed>
     */
    private static function scrubInternalWith(array $data, array $fields, array $patterns): array
    {
        $lowerFields = array_map('strtolower', $fields);

        foreach ($data as $key => $value) {
            if (is_string($key) && self::matchByList((string) $key, $lowerFields, $patterns) && ! self::isEmptyValue($value)) {
                $data[$key] = '******';
            } elseif (is_array($value)) {
                $data[$key] = self::scrubInternalWith($value, $fields, $patterns);
            }
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $lowerFields  已转小写的精确匹配列表
     * @param  array<int, string>  $patterns  正则
     */
    private static function matchByList(string $field, array $lowerFields, array $patterns): bool
    {
        if (in_array(strtolower($field), $lowerFields, true)) {
            return true;
        }

        foreach ($patterns as $pattern) {
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
     * 合并内置 + config 扩展嵌套敏感字段
     *
     * @return array<int, string>
     */
    private static function nestedFields(): array
    {
        $extra = (array) config('logs.scrubber.extra_nested_fields', []);
        $extra = array_filter(array_map(static fn ($v) => is_string($v) ? trim($v) : '', $extra), static fn ($v) => $v !== '');

        return array_values(array_unique(array_merge(self::SENSITIVE_NESTED_FIELDS, $extra)));
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
