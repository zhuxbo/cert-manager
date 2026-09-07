<?php

namespace App\Services\Upgrade;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class RedisDatabaseMigration
{
    /**
     * 复制后保留源库作为恢复副本，不清库、不覆盖目标已有的不同数据。
     *
     * @param  array<string, string>  $current
     * @param  array<string, string>  $target
     */
    public static function run(array $current, array $target, callable $publish): void
    {
        $oldCache = Cache::store();
        self::drainWorkers($oldCache);
        $store = $oldCache->getStore();
        $restartKey = $store instanceof RedisStore
            ? self::evaluate($store->connection(), 'return KEYS[1]', [$store->getPrefix().'illuminate:queue:restart'], 1) : '';
        $manager = new RedisManager(app(), config('database.redis.client', 'phpredis'), config('database.redis'));
        $snapshots = [];
        try {
            // 先记录两个源库，避免 0/1 → 1/2 时第二次扫描读到第一次刚写入的数据。
            foreach (['REDIS_DB' => 'default', 'REDIS_CACHE_DB' => 'cache'] as $key => $name) {
                if ($current[$key] === $target[$key]) {
                    continue;
                }
                $connection = $manager->connection($name);
                if ($connection->select((int) $current[$key]) !== true) {
                    throw new RuntimeException('无法选择 Redis 源数据库');
                }
                $snapshot = tmpfile();
                if ($snapshot === false) {
                    throw new RuntimeException('无法创建 Redis 迁移临时文件');
                }
                $snapshots[$name] = $snapshot;
                self::snapshot($connection, $snapshot, $restartKey);
                if ($connection->select((int) $target[$key]) !== true) {
                    throw new RuntimeException('无法选择 Redis 目标数据库');
                }
            }
            // 两个目标都先检查，再写入；同值允许中断后重试，异值不静默覆盖。
            foreach ([false, true] as $write) {
                foreach ($snapshots as $name => $snapshot) {
                    rewind($snapshot);
                    while (($line = fgets($snapshot)) !== false) {
                        [$key, $dump, $expires] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                        self::restore($manager->connection($name), base64_decode($key), base64_decode($dump), $expires, $write);
                    }
                }
            }
            $publish();
            // 已缓存的 manager/repository 可能还持有旧连接，升级进程也必须切到目标库。
            foreach (['REDIS_DB' => 'default', 'REDIS_CACHE_DB' => 'cache'] as $key => $name) {
                config(["database.redis.$name.database" => (int) $target[$key]]);
            }
            // 新进程已可读取目标编号，再通知维护期间重新拉起的旧 worker 退出。
            self::restartWorkers($oldCache);
            foreach ((array) Redis::connections() as $name => $connection) {
                if (isset(config('database.redis')[$name]['database'])) {
                    $connection->select((int) config("database.redis.$name.database"));
                }
            }
            app()->forgetInstance('redis');
            Redis::clearResolvedInstance('redis');
            Cache::forgetDriver(array_keys(config('cache.stores', [])));
        } finally {
            foreach ($snapshots as $snapshot) {
                fclose($snapshot);
            }
            foreach (array_keys((array) $manager->connections()) as $name) {
                $manager->purge($name);
            }
        }
    }

    /** @param resource $snapshot */
    private static function snapshot(Connection $connection, $snapshot, string $restartKey): void
    {
        $cursor = '0';
        do {
            // Lua 中的原始键不经过客户端 prefix/serializer；DUMP 保留所有 Redis 数据类型。
            $batch = self::evaluate($connection, <<<'LUA'
local scan = redis.call('SCAN', ARGV[1], 'COUNT', 100)
local now = redis.call('TIME')
local ms = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
local entries = {}
for _, key in ipairs(scan[2]) do
    local ttl = redis.call('PTTL', key)
    if key ~= ARGV[2] and (ttl == -1 or ttl > 0) then
        table.insert(entries, {key, redis.call('DUMP', key), ttl == -1 and 0 or ms + ttl})
    end
end
return {scan[1], entries}
LUA, [$cursor, $restartKey]);
            if (! is_array($batch) || count($batch) !== 2) {
                throw new RuntimeException('读取 Redis 源数据库失败');
            }
            [$cursor, $entries] = $batch;
            foreach ($entries as [$key, $dump, $expires]) {
                $line = json_encode([base64_encode($key), base64_encode($dump), $expires], JSON_THROW_ON_ERROR)."\n";
                if (fwrite($snapshot, $line) !== strlen($line)) {
                    throw new RuntimeException('保存 Redis 迁移数据失败');
                }
            }
        } while ((string) $cursor !== '0');
    }

    private static function restore(Connection $connection, string $key, string $dump, int $expires, bool $write): void
    {
        $result = self::evaluate($connection, <<<'LUA'
local now = redis.call('TIME')
local ms = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
local expires = tonumber(ARGV[3])
if expires > 0 and expires <= ms then return 1 end
local existing = redis.call('DUMP', ARGV[1])
if existing then
    if existing ~= ARGV[2] then return 0 end
    -- 重试不延长存量键的有效期。
    if ARGV[4] == '1' and expires > 0 then
        local ttl = redis.call('PTTL', ARGV[1])
        if ttl == -1 or ms + ttl > expires then redis.call('PEXPIREAT', ARGV[1], expires) end
    end
elseif ARGV[4] == '1' then
    redis.call('RESTORE', ARGV[1], expires == 0 and 0 or expires - ms, ARGV[2])
end
return 1
LUA, [$key, $dump, $expires, $write ? '1' : '0']);
        if ($result !== 1) {
            throw new RuntimeException('Redis 目标库存在同名但内容不同的数据，无法安全合并，源库保持原样');
        }
    }

    /** @param array<int, mixed> $arguments */
    private static function evaluate(Connection $connection, string $script, array $arguments, int $keys = 0): mixed
    {
        if ($connection instanceof PhpRedisConnection) {
            return $connection->eval($script, $keys, ...$arguments);
        }

        return $connection->command('eval', [$script, $keys, ...$arguments]);
    }

    private static function restartWorkers(mixed $cache): void
    {
        $restart = max(time(), (int) $cache->get('illuminate:queue:restart') + 1);
        if (! $cache->forever('illuminate:queue:restart', $restart)) {
            throw new RuntimeException('通知旧队列 worker 重启失败，已中止 Redis 迁移');
        }
    }

    private static function drainWorkers(mixed $cache): void
    {
        // Redis 队列在途 Job 完成后才能快照，否则会复制尚未确认的任务。
        if (config('queue.default') === 'redis' && ! is_dir('/proc')) {
            throw new RuntimeException('当前平台无法确认旧队列 worker 已退出，请使用 Linux 升级环境');
        }
        $workers = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $command = @file_get_contents($path);
            if (! is_string($command) || (int) basename(dirname($path)) === getmypid()) {
                continue;
            }
            $directory = dirname($path);
            $cwd = @readlink($directory.'/cwd');
            $arguments = explode("\0", $command);
            // schedule:work 只是常驻调度器；其在途 schedule:run/业务命令必须一起排空。
            if (in_array('schedule:work', $arguments, true) || in_array('serve', $arguments, true)) {
                continue;
            }
            $artisan = in_array('artisan', $arguments, true) || in_array(base_path('artisan'), $arguments, true);
            if ($artisan && ($cwd === base_path() || in_array(base_path('artisan'), $arguments, true))) {
                $workers[$path] = $command;
            }
        }
        self::restartWorkers($cache);
        $deadline = microtime(true) + 60;
        while ($workers !== []) {
            foreach ($workers as $path => $command) {
                if (@file_get_contents($path) !== $command) {
                    unset($workers[$path]);
                }
            }
            if ($workers === []) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('等待旧队列或定时命令退出超时，Redis 数据尚未迁移');
            }
            usleep(100000);
        }
    }
}
