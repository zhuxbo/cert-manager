<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class RuntimeCache
{
    public static function isIsolatedFromApplicationCache(): bool
    {
        $applicationStoreName = (string) config('cache.default');
        if ($applicationStoreName === 'runtime') {
            return false;
        }

        $applicationStore = config("cache.stores.{$applicationStoreName}");
        $runtimeStore = config('cache.stores.runtime');
        if (! is_array($applicationStore) || ! is_array($runtimeStore)) {
            return false;
        }

        if (($applicationStore['driver'] ?? null) !== 'redis' || ($runtimeStore['driver'] ?? null) !== 'redis') {
            return true;
        }

        $applicationConnection = (string) ($applicationStore['connection'] ?? 'default');
        $runtimeConnection = (string) ($runtimeStore['connection'] ?? 'default');

        return (int) config("database.redis.{$applicationConnection}.database")
            !== (int) config("database.redis.{$runtimeConnection}.database");
    }

    public static function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        $store = Cache::store('runtime')->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Runtime cache store does not support atomic locks');
        }

        return $store->lock($name, $seconds, $owner);
    }
}
