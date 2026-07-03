<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'ssl'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => env('DB_COLLATION') ?: null,  // 空值使用 MySQL 默认
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            // 锁等待超时固化：每次建立 PDO 连接时执行 SET SESSION innodb_lock_wait_timeout=50。
            // session 值覆盖 global，保证「锁内上游 45s < innodb 50s < worker --timeout 60s」三层递进的
            // 中间层不受云 RDS / DBA 的 global 配置漂移影响（Cache 故障 fail-open 退回 DB 锁串行时的最后防线）。
            // 值必须是 50：<45 会让锁内上游正常 45s 误撞 1205；=60 会与 worker 60s 重合成时序竞争。
            // extension_loaded 守卫：无 pdo_mysql 的环境加载本 config 不引用未定义常量。
            // SSL/TLS 连云库时，在此 options 数组内追加 PDO::MYSQL_ATTR_SSL_* 选项。
            'options' => extension_loaded('pdo_mysql') ? [
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION innodb_lock_wait_timeout=50',
            ] : [],
        ],
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'ssl'), '_').'_prefix_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

    // 数据库备份
    'backup' => [
        // 保留天数；0 表示不自动清理。仅按天清理 backup_ 前缀（pre_restore_ 不参与，见下方 pre_restore_keep）
        'keep_days' => (int) env('DB_BACKUP_KEEP_DAYS', 30),
        // 兜底最少保留份数：即使超过 keep_days，也始终保留最近 N 份 backup_，防止全部被清空
        'min_keep' => (int) env('DB_BACKUP_MIN_KEEP', 3),
        // 恢复前 pre_restore_ 快照的数量上限：每次拍快照后保留最近 N 份、清理更旧的；0 表示不限制（永久保留）。
        // 防止恢复重试（tries>1）或多次恢复导致 pre_restore_ 快照无限累积占盘。
        'pre_restore_keep' => (int) env('DB_BACKUP_PRE_RESTORE_KEEP', 5),
    ],
];
