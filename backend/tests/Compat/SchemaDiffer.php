<?php

declare(strict_types=1);

namespace Tests\Compat;

/**
 * Schema 提取与差异计算工具。
 *
 * 配套 API 兼容性快照对照：
 * - extractSchema()：把任意 PHP 值递归转成"形状"（标量返回类型字符串、对象/关联数组返回 key→schema 映射、列表返回 array<...> 字符串）
 * - diff()：对比两个 schema，返回差异列表（缺字段 / 多字段 / 类型变化）
 *
 * fixture 内只存"形状"，不存真实值（id/时间戳/邮箱等敏感数据），保证 fixture 可入 git 且多次运行确定。
 */
final class SchemaDiffer
{
    /**
     * 从值提取 schema。
     *
     * 规则：
     * - null / bool / int / float / string → 对应类型字符串
     * - 列表数组（顺序索引）→ "array<元素 schema>"；元素为对象时取首元素并合并所有元素的 key 集（处理列表内部分元素缺字段）
     * - 关联数组 / 对象 → 关联数组（递归）
     */
    public static function extractSchema(mixed $value): mixed
    {
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_array($value)) {
            if ($value === []) {
                return 'array<unknown>';
            }
            if (array_is_list($value)) {
                $first = $value[0];
                // 对象列表：合并所有元素的 key（避免某条记录缺字段导致 fixture 不稳定）
                if (is_array($first) && ! array_is_list($first)) {
                    $merged = [];
                    foreach ($value as $item) {
                        if (! is_array($item) || array_is_list($item)) {
                            // 列表元素类型不一致，降级为 array<mixed>
                            return 'array<mixed>';
                        }
                        foreach ($item as $k => $v) {
                            $itemSchema = self::extractSchema($v);
                            // 同一 key 出现：非 null schema 优先（覆盖此前的 'null' 占位），
                            // 避免 fixture 把"恰好首条值为 null"的字段固化为 schema='null'
                            // 导致后续 compare 时无法识别真实类型变化（reviewer 问题 3）。
                            if (! array_key_exists($k, $merged) || $merged[$k] === 'null') {
                                $merged[$k] = $itemSchema;
                            }
                        }
                    }

                    return ['__list_of__' => $merged];
                }

                $elem = self::extractSchema($first);

                return is_array($elem) ? 'array<mixed>' : "array<{$elem}>";
            }

            // 关联数组
            $result = [];
            foreach ($value as $key => $v) {
                $result[(string) $key] = self::extractSchema($v);
            }

            return $result;
        }
        if (is_object($value)) {
            return 'object';
        }

        return 'unknown';
    }

    /**
     * 对比两个 schema，返回差异列表。
     *
     * 每条差异格式：
     *  ['path' => 'data.list.0.id', 'kind' => 'missing|added|type_changed', 'expected' => ..., 'actual' => ...]
     *
     * - 期望有 actual 没有 → kind=missing
     * - actual 有期望没有 → kind=added
     * - 都有但类型不一样 → kind=type_changed
     *
     * @return array<int, array<string, mixed>>
     */
    public static function diff(mixed $expected, mixed $actual, string $path = '$'): array
    {
        // null/null 视为完全相等（fixture 没拿到响应 body 时为 null）
        if ($expected === null && $actual === null) {
            return [];
        }

        // null vs schema：当作 missing/added
        if ($expected === null) {
            return [[
                'path' => $path,
                'kind' => 'added',
                'expected' => null,
                'actual' => $actual,
            ]];
        }
        if ($actual === null) {
            return [[
                'path' => $path,
                'kind' => 'missing',
                'expected' => $expected,
                'actual' => null,
            ]];
        }

        // 字符串 schema：直接比较
        // 不再做 null↔scalar 容忍：reviewer 问题 3 指出双向容忍会漏报 expected=null→actual=string（之前数据全 null
        // 现在有值时 silently 放过）。null 由 extractSchema 列表合并阶段降级处理，diff 阶段保持严格。
        if (is_string($expected) && is_string($actual)) {
            if ($expected === $actual) {
                return [];
            }

            return [[
                'path' => $path,
                'kind' => 'type_changed',
                'expected' => $expected,
                'actual' => $actual,
            ]];
        }

        // 一边字符串一边数组：类型变化
        if (is_string($expected) !== is_string($actual)) {
            return [[
                'path' => $path,
                'kind' => 'type_changed',
                'expected' => is_string($expected) ? $expected : 'object',
                'actual' => is_string($actual) ? $actual : 'object',
            ]];
        }

        // 双数组：递归对比
        if (is_array($expected) && is_array($actual)) {
            // 对象列表：用 __list_of__ 包装的 key
            if (isset($expected['__list_of__']) && isset($actual['__list_of__'])) {
                return self::diff($expected['__list_of__'], $actual['__list_of__'], $path.'.[]');
            }
            // 一边是对象列表一边不是
            if (isset($expected['__list_of__']) !== isset($actual['__list_of__'])) {
                return [[
                    'path' => $path,
                    'kind' => 'type_changed',
                    'expected' => isset($expected['__list_of__']) ? 'list_of_object' : 'object',
                    'actual' => isset($actual['__list_of__']) ? 'list_of_object' : 'object',
                ]];
            }

            $diffs = [];
            foreach ($expected as $key => $expectedValue) {
                $childPath = $path.'.'.$key;
                if (! array_key_exists($key, $actual)) {
                    $diffs[] = [
                        'path' => $childPath,
                        'kind' => 'missing',
                        'expected' => $expectedValue,
                        'actual' => null,
                    ];

                    continue;
                }
                $diffs = array_merge($diffs, self::diff($expectedValue, $actual[$key], $childPath));
            }
            foreach ($actual as $key => $actualValue) {
                if (! array_key_exists($key, $expected)) {
                    $diffs[] = [
                        'path' => $path.'.'.$key,
                        'kind' => 'added',
                        'expected' => null,
                        'actual' => $actualValue,
                    ];
                }
            }

            return $diffs;
        }

        // 不应到达这里
        return [[
            'path' => $path,
            'kind' => 'type_changed',
            'expected' => gettype($expected),
            'actual' => gettype($actual),
        ]];
    }
}
