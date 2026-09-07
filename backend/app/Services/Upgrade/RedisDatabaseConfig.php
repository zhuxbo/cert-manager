<?php

namespace App\Services\Upgrade;

use App\Support\ApplicationBootstrapLock;
use Dotenv\Dotenv;
use Dotenv\Parser\Lines;
use Dotenv\Parser\Parser;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class RedisDatabaseConfig
{
    public static function preserve(bool $bootstrapLockHeld = false, ?string $sitesRoot = null): void
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

        $current = $databases = [];
        foreach (['REDIS_DB' => 'default', 'REDIS_CACHE_DB' => 'cache'] as $key => $connection) {
            $current[$key] = self::databaseNumber(config("database.redis.$connection.database"), $key);
            $databases[$key] = array_key_exists($key, $env)
                ? self::databaseNumber($env[$key], $key) : $current[$key];
        }

        $bootstrapLock = null;
        try {
            if ($databases['REDIS_DB'] === $databases['REDIS_CACHE_DB']) {
                $sitesRoot ??= getenv('MANAGER_SITES_ROOT') ?: '/www/wwwroot';
                $databases['REDIS_CACHE_DB'] = self::availableCacheDatabase($sitesRoot, $databases['REDIS_DB']);
            }
            if ($databases !== $current) {
                if (! app()->isDownForMaintenance()) {
                    throw new RuntimeException('Redis 编号变更必须在维护模式下迁移，请开启维护模式后重试升级');
                }
                if (! $bootstrapLockHeld) {
                    $bootstrapLock = ApplicationBootstrapLock::acquireExclusive();
                }
                // 目标数据全部就绪后才清除配置缓存；失败时旧库仍可继续使用。
                RedisDatabaseMigration::run($current, $databases, function () use ($path, $content, $databases) {
                    self::writeDatabases($path, $content, $databases);
                    if (Artisan::call('config:clear') !== 0) {
                        throw new RuntimeException('清除旧 Redis 配置缓存失败，已中止升级');
                    }
                });
            } else {
                self::writeDatabases($path, $content, $databases);
            }
        } finally {
            ApplicationBootstrapLock::release($bootstrapLock);
        }
    }

    private static function databaseNumber(mixed $value, string $key): string
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d+$/D', (string) $value)) {
            throw new RuntimeException("无法确定当前 {$key}，请配置有效数据库编号后重试升级");
        }
        $number = ltrim((string) $value, '0') ?: '0';
        if (filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new RuntimeException("无法确定当前 {$key}，请配置有效数据库编号后重试升级");
        }

        return $number;
    }

    /** @param array<string, string> $databases */
    private static function writeDatabases(string $path, string $content, array $databases): void
    {
        // 使用 dotenv 的逻辑行，避免误改其他多行值中看似 Redis 配置的文本。
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $normalized = str_replace("\r\n", "\n", $content);
        $updated = '';
        $offset = 0;
        $seen = [];
        foreach (Lines::process(explode("\n", $normalized)) as $raw) {
            $entry = (new Parser)->parse($raw)[0];
            $pattern = str_replace("\n", '\r?\n', preg_quote($raw, '/'));
            if (! preg_match('/^'.$pattern.'(?=\r?$)/m', $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
                throw new RuntimeException('无法定位 Redis 配置，已中止升级');
            }
            $position = $match[0][1];
            $updated .= substr($content, $offset, $position - $offset);
            $offset = $position + strlen($match[0][0]);
            $key = $entry->getName();
            if (! isset($databases[$key])) {
                $updated .= $match[0][0];
            } elseif (! isset($seen[$key])) {
                $updated .= "$key={$databases[$key]}";
                $seen[$key] = true;
            } elseif (preg_match('/\G(?:\r\n|\n)/', $content, $ending, 0, $offset)) {
                $offset += strlen($ending[0]);
            }
        }
        $updated .= substr($content, $offset);
        foreach (array_diff_key($databases, $seen) as $key => $value) {
            $updated .= ($updated === '' || str_ends_with($updated, "\n") ? '' : $newline)."$key=$value$newline";
        }
        if ($updated === $content) {
            return;
        }
        // 原文件内写入以保留属主和权限；已有键原位更新，只追加缺失键。
        if (File::put($path, $updated, true) !== strlen($updated)) {
            throw new RuntimeException('保存当前 Redis 数据库编号失败，已中止升级');
        }
    }

    /** 兼容脚本收尾入口；两种升级统一按 .env 决定目标编号。 */
    public static function separateCacheDatabase(string $sitesRoot): void
    {
        self::preserve(false, $sitesRoot);
    }

    private static function availableCacheDatabase(string $sitesRoot, string $runtime): string
    {
        $probe = null;
        try {
            $used = [$runtime => true];
            $endpoint = self::endpoint(config('database.redis.cache.host', '127.0.0.1'), config('database.redis.cache.port', 6379));
            foreach (glob($sitesRoot.'/*/backend/.env') ?: [] as $path) {
                if (realpath($path) === realpath(app()->environmentFilePath())) {
                    continue;
                }
                $env = Dotenv::parse(File::get($path));
                if (! empty($env['REDIS_URL'])) {
                    throw new RuntimeException("现有站点 $path 使用 REDIS_URL，无法确定占用编号");
                }
                if (self::endpoint($env['REDIS_HOST'] ?? '127.0.0.1', $env['REDIS_PORT'] ?? 6379) !== $endpoint) {
                    continue;
                }
                foreach (['REDIS_DB', 'REDIS_CACHE_DB'] as $key) {
                    if (! isset($env[$key])) {
                        // 存量安装的默认编号存在版本差异，保守预留 0/1/2。
                        $used[0] = $used[1] = $used[2] = true;

                        continue;
                    }
                    if (! preg_match('/^\d+$/D', $env[$key])) {
                        throw new RuntimeException("现有站点 $path 的 $key 无法识别");
                    }
                    $used[(int) $env[$key]] = true;
                }
            }

            // resolve 创建独立连接；SELECT 不改变当前应用已经使用的 cache 连接。
            $probe = Redis::resolve('cache');
            for ($candidate = 1; $candidate < 16; $candidate++) {
                if (isset($used[$candidate])) {
                    continue;
                }
                if ($probe->select($candidate) !== true) {
                    throw new RuntimeException('Redis 不支持候选数据库编号，无法分配缓存库');
                }
                $size = $probe->dbsize();
                if (! is_int($size) || $size < 0) {
                    throw new RuntimeException('无法确认 Redis 候选数据库是否为空');
                }
                if ($size !== 0) {
                    continue;
                }

                return (string) $candidate;
            }
            throw new RuntimeException('Redis DB 1-15 没有空闲缓存编号，当前 Manager 配置保持原样');
        } finally {
            if ($probe instanceof PhpRedisConnection) {
                $probe->disconnect();
            } elseif ($probe !== null) {
                $probe->client()->disconnect();
            }
        }
    }

    private static function endpoint(mixed $host, mixed $port): string
    {
        $host = strtolower((string) $host);
        if ($host === 'localhost') {
            $host = '127.0.0.1';
        }
        if ($host === '' || str_contains($host, '$') || ! preg_match('/^\d+$/D', (string) $port)) {
            throw new RuntimeException('Redis 连接配置无法识别，不能分配缓存编号');
        }

        return $host.':'.(int) $port;
    }
}
