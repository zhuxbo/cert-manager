<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * MySQL 兼容的 auto_settings JSON 路径查询助手（AutoRenewCommand 与 BalanceForecastCommand 共用）。
 *
 * auto_settings 列存 text + array cast；用 JSON_UNQUOTE(JSON_EXTRACT(...)) 字符串比较，
 * 与 model auto_settings cast 'array' 序列化后的 JSON 表示匹配。
 *
 * 说明：纯移动自 AutoRenewCommand（方法体逐字不变，零行为变更），抽为共享 trait 避免两命令
 * whereRaw 漂移；既有 AutoRenewCommandTest 全绿即为「移动无副作用」的守卫。
 */
trait QueriesUserJsonSettings
{
    /**
     * MySQL 兼容的 JSON 路径布尔比较（auto_settings 列存的是 text + array cast）。
     *
     * 用 JSON_UNQUOTE(JSON_EXTRACT(...)) = 'true' / 'false' 字符串比较，
     * 与 model auto_settings cast 'array' 序列化后的 JSON 表示匹配。
     */
    protected function whereJsonBoolEq(Builder $query, string $column, string $key, bool $value): Builder
    {
        $jsonPath = '$.'.json_encode($key);
        $expected = $value ? 'true' : 'false';

        return $query->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT($column, ?)) = ?",
            [$jsonPath, $expected]
        );
    }

    /**
     * MySQL 兼容的 JSON 路径不存在（key missing 或 value 是 JSON null）。
     */
    protected function whereJsonKeyMissing(Builder $query, string $column, string $key): Builder
    {
        $jsonPath = '$.'.json_encode($key);

        return $query->whereRaw(
            "JSON_EXTRACT($column, ?) IS NULL OR JSON_TYPE(JSON_EXTRACT($column, ?)) = 'NULL'",
            [$jsonPath, $jsonPath]
        );
    }
}
