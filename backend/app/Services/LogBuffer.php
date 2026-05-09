<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 日志缓冲服务
 *
 * 将日志缓存在内存中，请求结束后批量写入数据库。
 * 自动从容器读取 `correlation_id` 注入每条日志数据，
 * 单 model 缓冲达上限时自动 flush 该 model。
 */
class LogBuffer
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $logs = [];

    /**
     * 添加日志到缓冲区
     *
     * - 自动从 `app('correlation_id')` 注入 correlation_id（命令行 / 测试场景下容器未绑定时跳过）
     * - 单 model 缓冲达上限时立即 flush 该 model（避免单次请求堆积过多内存）
     *
     * @param  class-string  $model  模型类名
     * @param  array<string, mixed>  $data  日志数据
     */
    public static function add(string $model, array $data): void
    {
        // 兜底：未绑定 correlation_id 时不报错（命令行 / 测试 / 队列冷启动场景）
        if (! array_key_exists('correlation_id', $data) && app()->bound('correlation_id')) {
            $data['correlation_id'] = app('correlation_id');
        }

        if (! isset(self::$logs[$model])) {
            self::$logs[$model] = [];
        }
        self::$logs[$model][] = $data;

        // 单 model 缓冲达上限即 flush（避免内存积压）
        $bufferMax = (int) (function_exists('config') ? config('logs.buffer_max', 200) : 200);
        if ($bufferMax > 0 && count(self::$logs[$model]) >= $bufferMax) {
            self::flushModel($model);
        }
    }

    /**
     * 刷新缓冲区，批量写入所有日志
     */
    public static function flush(): void
    {
        if (empty(self::$logs)) {
            return;
        }

        foreach (array_keys(self::$logs) as $model) {
            self::flushModel($model);
        }

        self::$logs = [];
    }

    /**
     * 刷新单个 model 的缓冲（达上限时调）
     *
     * @param  class-string  $model
     */
    public static function flushModel(string $model): void
    {
        $items = self::$logs[$model] ?? [];

        if (empty($items)) {
            return;
        }

        try {
            // 添加 created_at 时间戳
            $now = now()->toDateTimeString();
            $itemsWithTimestamp = array_map(function ($item) use ($model, $now) {
                $item['created_at'] = $now;
                $item = self::filterInsertableColumns($model, $item);

                // 手动 JSON 序列化数组/对象值，因为 Model::insert() 走 Query Builder 不触发 Eloquent cast
                foreach ($item as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        $item[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                }

                return $item;
            }, $items);

            // 统一所有 item 的列集合：同批 buffer 内 correlation_id 可能部分有部分无
            // （add 仅在容器绑定时注入），PG 严格校验 VALUES 长度会直接 SQL ERROR
            $allKeys = [];
            foreach ($itemsWithTimestamp as $item) {
                $allKeys += array_flip(array_keys($item));
            }
            $allKeys = array_keys($allKeys);
            $itemsWithTimestamp = array_map(function ($item) use ($allKeys) {
                foreach ($allKeys as $key) {
                    if (! array_key_exists($key, $item)) {
                        $item[$key] = null;
                    }
                }

                return $item;
            }, $itemsWithTimestamp);

            DB::transaction(static fn () => $model::insert($itemsWithTimestamp));
        } catch (Throwable $e) {
            // 批量插入失败时，尝试逐条插入
            \Log::warning('LogBuffer: batch insert failed, fallback to single insert', [
                'model' => $model,
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
            foreach ($items as $item) {
                try {
                    $item = self::filterInsertableColumns($model, $item, false);
                    DB::transaction(static fn () => $model::create($item));
                } catch (Throwable $createException) {
                    // 单条插入也失败时，记录到文件日志
                    \Log::error('LogBuffer: Failed to insert log', [
                        'model' => $model,
                        'data' => $item,
                        'error' => $createException->getMessage(),
                    ]);
                }
            }
        }

        self::$logs[$model] = [];
    }

    /**
     * 过滤掉模型不可批量写入的列。
     *
     * LogBuffer 的批量 insert 不走 Eloquent fillable 保护。这里按日志模型
     * fillable 裁剪一遍，避免 callback_logs 这类字段较少的表被通用日志字段击穿。
     *
     * @param  class-string  $model
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function filterInsertableColumns(string $model, array $item, bool $includeCreatedAt = true): array
    {
        if (! is_subclass_of($model, Model::class)) {
            return $item;
        }

        $fillable = (new $model)->getFillable();
        if (empty($fillable)) {
            return $item;
        }

        if ($includeCreatedAt) {
            $fillable[] = 'created_at';
        }

        return array_intersect_key($item, array_flip($fillable));
    }

    /**
     * 清空缓冲区（不写入）
     */
    public static function clear(): void
    {
        self::$logs = [];
    }

    /**
     * 获取缓冲区中的日志数量
     */
    public static function count(): int
    {
        $count = 0;
        foreach (self::$logs as $items) {
            $count += count($items);
        }

        return $count;
    }
}
