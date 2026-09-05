<?php

namespace App\Services\Upgrade;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class RedisDatabaseConfig
{
    public static function preserve(): void
    {
        if (config('cache.default') !== 'redis' && config('queue.default') !== 'redis') {
            return;
        }

        $path = app()->environmentFilePath();
        $content = File::get($path);
        $env = Dotenv::parse($content);
        if (! empty($env['REDIS_URL']) || config('database.redis.default.url') || config('database.redis.cache.url')) {
            throw new RuntimeException('请先将 REDIS_URL 改为显式 Redis 连接配置和数据库编号，再重试升级');
        }

        $databases = [];
        foreach (['REDIS_DB' => 'default', 'REDIS_CACHE_DB' => 'cache'] as $key => $connection) {
            // 必须读取旧应用已加载的配置（含配置缓存），不能使用新版本的默认值。
            $value = config("database.redis.$connection.database");
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d+$/D', (string) $value)) {
                throw new RuntimeException("无法确定当前 {$key}，请配置有效数据库编号后重试升级");
            }
            $number = ltrim((string) $value, '0') ?: '0';
            if (filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                throw new RuntimeException("无法确定当前 {$key}，请配置有效数据库编号后重试升级");
            }
            $databases[$key] = $number;
        }

        if ($databases['REDIS_DB'] === $databases['REDIS_CACHE_DB']) {
            throw new RuntimeException('当前 REDIS_DB 与 REDIS_CACHE_DB 相同，请先分开配置并处理现有队列，再重试升级');
        }

        $lines = [];
        foreach ($databases as $key => $value) {
            if (($env[$key] ?? null) !== $value) {
                $lines[] = "$key=$value";
            }
        }
        if ($lines === []) {
            return;
        }

        // phpdotenv 以后出现的赋值为准；追加保留其它配置、文件属主与权限，重复执行不再追加。
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $suffix = (str_ends_with($content, "\n") ? '' : $newline).implode($newline, $lines).$newline;
        if (File::append($path, $suffix, true) !== strlen($suffix)) {
            throw new RuntimeException('保存当前 Redis 数据库编号失败，已中止升级');
        }
    }
}
