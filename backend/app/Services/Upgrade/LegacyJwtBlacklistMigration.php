<?php

namespace App\Services\Upgrade;

use App\Support\ApplicationBootstrapLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\RedisTagSet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class LegacyJwtBlacklistMigration
{
    public const string FILE_KEY_PREFIX = 'legacy-jwt-file:';

    public static function run(): void
    {
        // 旧升级进程尚未加载 runtime 配置，按当前实例实际驱动补齐。
        if (config('cache.stores.runtime') === null) {
            $redis = config('cache.default') === 'redis' || config('queue.default') === 'redis';
            config(['cache.stores.runtime' => $redis
                ? ['driver' => 'redis', 'connection' => 'default']
                : ['driver' => 'file', 'path' => storage_path('framework/runtime-cache/data')]]);
        }

        $lock = ApplicationBootstrapLock::acquireExclusive();
        try {
            $source = Cache::store()->getStore();
            if ($source instanceof RedisStore) {
                self::copyRedis($source);
            } elseif ($source instanceof FileStore) {
                self::copyFiles($source);
            } elseif (! $source instanceof ArrayStore) {
                throw new RuntimeException('不支持的旧 JWT 缓存驱动，保留旧黑名单并中止迁移');
            }
        } finally {
            ApplicationBootstrapLock::release($lock);
        }
    }

    private static function copyRedis(RedisStore $source): void
    {
        $tags = new RedisTagSet($source, ['tymon.jwt']);
        $namespace = sha1($tags->getNamespace()).':';
        $target = Cache::store('runtime')->tags('tymon.jwt');
        foreach ($tags->entries() as $entry) {
            if (! str_starts_with($entry, $namespace)) {
                throw new RuntimeException('旧 JWT 黑名单标签格式不兼容');
            }
            // 先取剩余寿命，复制时使用绝对到期时间，重试不会延长有效期。
            $ttl = $source->connection()->pttl($source->getPrefix().$entry);
            if ($ttl === -2 || $ttl === 0) {
                continue;
            }
            $expires = $ttl === -1 ? null : now()->addMilliseconds($ttl);
            $value = $source->get($entry);
            if ($value === null) {
                continue;
            }
            $key = substr($entry, strlen($namespace));
            if (! $target->add($key, $value, $expires) && $target->get($key) === null) {
                throw new RuntimeException('复制 JWT 黑名单失败，保留旧缓存');
            }
        }
    }

    private static function copyFiles(FileStore $source): void
    {
        $directory = $source->getDirectory();
        if (! is_dir($directory)) {
            return;
        }
        $target = Cache::store('runtime');
        foreach (File::allFiles($directory) as $file) {
            $hash = $file->getFilename();
            if (! preg_match('/^[a-f0-9]{40}$/D', $hash)) {
                continue;
            }
            $content = File::get($file->getPathname());
            $expires = (int) substr($content, 0, 10);
            if ($expires <= time()) {
                continue;
            }
            $value = @unserialize(substr($content, 10), ['allowed_classes' => false]);
            if ($value !== 'forever' && (! is_array($value) || array_keys($value) !== ['valid_until'])) {
                continue;
            }
            // FileStore 不保存原始 jti，用相同 SHA1 查找已搬迁的历史记录。
            $key = self::FILE_KEY_PREFIX.$hash;
            if (! $target->add($key, $value, now()->setTimestamp($expires)) && $target->get($key) === null) {
                throw new RuntimeException('复制文件 JWT 黑名单失败，保留旧缓存');
            }
        }
    }
}
