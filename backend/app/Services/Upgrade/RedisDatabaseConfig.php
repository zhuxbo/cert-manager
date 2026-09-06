<?php

namespace App\Services\Upgrade;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

final class RedisDatabaseConfig
{
    public static function preserve(bool $allowSharedDatabase = false): void
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

        if (! $allowSharedDatabase && $databases['REDIS_DB'] === $databases['REDIS_CACHE_DB']) {
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

    /** 仅脚本升级在旧 JWT 黑名单迁移完成后调用；后台升级不自动分配。 */
    public static function separateCacheDatabase(string $sitesRoot): void
    {
        if (config('cache.default') !== 'redis' && config('queue.default') !== 'redis') {
            return;
        }
        self::preserve(true);
        $runtime = (int) config('database.redis.default.database');
        if ($runtime !== (int) config('database.redis.cache.database')) {
            return;
        }

        // 与安装器共用同一个 flock，分配与写入 .env 必须位于同一临界区。
        $lock = fopen($sitesRoot.'/.ssl-manager-redis-db.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('无法打开 Redis DB 分配锁');
        }
        $probe = null;
        try {
            $deadline = microtime(true) + 30;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('等待 Redis DB 分配锁超时');
                }
                usleep(100000);
            }
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
                // 读取旧 repository，固定连接；旧 worker 只会监听原缓存库的重启信号。
                $oldCache = Cache::store();
                $restart = max(time(), (int) $oldCache->get('illuminate:queue:restart') + 1);
                try {
                    config(['database.redis.cache.database' => $candidate]);
                    self::preserve();
                    if (Artisan::call('config:clear') !== 0) {
                        throw new RuntimeException('清除旧 Redis 配置缓存失败，已中止分库');
                    }
                    // 必须先让后继进程能读取新配置，同秒重试也需要一个变化的信号。
                    if (! $oldCache->forever('illuminate:queue:restart', $restart)) {
                        throw new RuntimeException('通知旧队列 worker 重启失败，已中止分库');
                    }
                } catch (Throwable $e) {
                    config(['database.redis.cache.database' => $runtime]);
                    self::preserve(true);

                    throw $e;
                }

                return;
            }
            throw new RuntimeException('Redis DB 1-15 没有空闲缓存编号，当前 Manager 配置保持原样');
        } finally {
            try {
                $probe?->client()->close();
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
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
