<?php

/**
 * 升级配置
 */

return [
    // 升级进程「无心跳视为已死」的阈值（秒）——watchdog 与 UpgradeStatusManager::isStale 共用。
    // SIGKILL/OOM 打断升级后，维护/冻结最长卡 stale_seconds + 1min watchdog 周期才被解除。
    // PID 存活探测已根除慢单步（大库 migrate / 慢镜像 composer）误判，此阈值不再承担误判防线、
    // 纯粹是「进程死后多快恢复」的旋钮，资源紧张环境可 env 下调。默认 3600s。
    'stale_seconds' => (int) env('UPGRADE_STALE_SECONDS', 3600),

    // 备份配置
    'backup' => [
        // 备份存储目录（项目根目录 backups）
        'path' => dirname(base_path()).'/backups',

        // 最大保留备份数量
        'max_backups' => env('UPGRADE_MAX_BACKUPS', 5),

        // 备份内容
        'include' => [
            'backend' => true,      // 后端代码
            'frontend' => true,     // 前端（编译后的 dist 目录）
            'database' => false,    // 数据库（升级前 BackupService 已单独全量 mysqldump，避免重复）
        ],

        // 数据库备份排除的表（日志表、队列表、通知记录等非核心数据）
        'exclude_tables' => [
            // 日志表
            'ca_logs',
            'callback_logs',
            'api_logs',
            'user_logs',
            'admin_logs',
            'error_logs',
            // 队列表
            'jobs',
            'failed_jobs',
            'job_batches',
            // 通知记录
            'notifications',
        ],
    ],

    // 升级包配置
    'package' => [
        // 下载目录（项目根目录 backups/upgrades）
        'download_path' => dirname(base_path()).'/backups/upgrades',

        // 下载超时（秒）
        'download_timeout' => 300,

        // 自动清理旧的升级包
        'auto_cleanup' => true,

        // 升级包保留天数
        'retention_days' => 30,
    ],

    // 升级行为配置
    'behavior' => [
        // 升级前是否强制备份
        'force_backup' => env('UPGRADE_FORCE_BACKUP', true),

        // 升级前是否进入维护模式
        'maintenance_mode' => env('UPGRADE_MAINTENANCE_MODE', true),

        // 升级后是否自动清理缓存
        'clear_cache' => env('UPGRADE_CLEAR_CACHE', true),

        // 升级后是否自动运行迁移
        'auto_migrate' => env('UPGRADE_AUTO_MIGRATE', true),

        // 升级后是否自动运行种子（seeder 有防御性检测，可重复执行）
        'auto_seed' => env('UPGRADE_AUTO_SEED', true),

        // 指定种子类，null 表示 DatabaseSeeder
        'seed_class' => null,

        // 升级后是否自动校验数据库结构
        'auto_structure_check' => env('UPGRADE_AUTO_STRUCTURE_CHECK', true),

        // 发现差异时是否自动修复（仅 ADD 类型：缺失的表、列、索引）
        'auto_structure_fix' => env('UPGRADE_AUTO_STRUCTURE_FIX', true),

        // 列注释严格比对
        'strict_comment_check' => env('UPGRADE_STRICT_COMMENT_CHECK', false),
    ],

    // 结构校验时排除的系统表
    'exclude_tables' => [
        'migrations',
        'failed_jobs',
        'password_reset_tokens',
        'personal_access_tokens',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'queue_batches',
    ],

    // 版本限制
    'constraints' => [
        // 最低支持的 PHP 版本
        'min_php_version' => '8.3.0',

        // 不允许降级
        'allow_downgrade' => false,

        // 允许跨版本升级（全量替换方式）
        'require_sequential' => false,
    ],
];
