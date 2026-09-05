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
            // Redis URL 的 path/query 会在 Laravel 连接阶段覆盖 database，破坏双库隔离；
            // 统一使用下面的显式连接字段，数据库编号只由 REDIS_DB 决定。
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '1'),
            // 网络黑洞快速失败：连接超时 + 命令读超时。键名对 phpredis（本仓唯一 client，
            // PhpRedisConnector 把 read_timeout 映射到 OPT_READ_TIMEOUT + connect 第 6 参）。
            // 不加 read_write_timeout —— 那是 Predis 专属键、phpredis 会静默忽略（留着即误导）。
            // 黑洞时 5s 抛 RedisException → MutexLock/Cache::lock fail-open 退 DB 锁串行
            // （与 innodb_lock_wait_timeout=50 同哲学的三层递进）。block_for=null 时非阻塞轮询不受影响；
            // 未来若设 block_for>0 或用 redis 阻塞 pop，read_timeout 必须 > block_for（或 -1）。
            'timeout' => (float) env('REDIS_TIMEOUT', 5),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5),
        ],

        'cache' => [
            // 与 default 对称，不接受 REDIS_URL 隐式覆盖 REDIS_CACHE_DB。
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '2'),
            // 与 default 对称：连接超时 + 命令读超时（phpredis OPT_READ_TIMEOUT）。
            'timeout' => (float) env('REDIS_TIMEOUT', 5),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5),
        ],
    ],

    // 数据库备份
    'backup' => [
        // 保留天数；0 表示不自动清理。仅清理新创建的 backup_ 前缀备份。
        'keep_days' => (int) env('DB_BACKUP_KEEP_DAYS', 30),
        // 兜底最少保留份数：即使超过 keep_days，也始终保留最近 N 份 backup_，防止全部被清空
        'min_keep' => (int) env('DB_BACKUP_MIN_KEEP', 3),
    ],
];
