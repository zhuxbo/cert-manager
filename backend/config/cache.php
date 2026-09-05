<?php

use Illuminate\Support\Str;

$cacheDriver = env('CACHE_DRIVER', 'file');
$runtimeDriver = $cacheDriver === 'redis' || env('QUEUE_CONNECTION', 'database') === 'redis'
    ? 'redis'
    : $cacheDriver;

$runtimeStore = match ($runtimeDriver) {
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'lock_connection' => 'default',
    ],
    'array' => [
        'driver' => 'array',
        'serialize' => false,
    ],
    default => [
        'driver' => 'file',
        'path' => storage_path('framework/runtime-cache/data'),
        'lock_path' => storage_path('framework/runtime-cache/data'),
    ],
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

        // 关键运行状态与可清理缓存隔离；Redis 模式使用 REDIS_DB。
        'runtime' => $runtimeStore,

    ],

    // 登录与验证码限流属于安全状态，不应被普通缓存清理重置。
    'limiter' => 'runtime',

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'ssl'), '_').'_cache_'),

];
