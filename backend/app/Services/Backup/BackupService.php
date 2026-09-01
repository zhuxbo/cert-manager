<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 数据库备份服务：列表/删除/进度读写/下载 token。
 *
 * 产物约定：
 * {prefix}_YYYYMMDD_HHMMSS.sql.gz — mysqldump + gzip
 * {prefix}_YYYYMMDD_HHMMSS.schema.json — 当前数据库结构（同名异后缀）
 * 新备份只创建 backup 前缀；列表/下载/删除仍识别历史 pre_restore 前缀文件。
 */
class BackupService
{
    /** 进度信息 Cache key 前缀 */
    public const JOB_CACHE_PREFIX = 'backup:job:';

    /** 遗留恢复流程仍使用的 Cache 锁键；新备份互斥由 DatabaseOperationMutex 负责。 */
    public const MUTEX_LOCK_KEY = 'backup:mutex';

    /** 下载 token Cache key 前缀 */
    public const DOWNLOAD_TOKEN_PREFIX = 'backup:download:';

    /** 下载 token 有效期（秒） */
    public const DOWNLOAD_TOKEN_TTL = 300;

    /** Job 进度信息保留时长（秒） */
    public const JOB_CACHE_TTL = 3600;

    /**
     * 备份时只保留结构、重置数据的运行时表（日志表通过 %_logs 动态发现并完全排除）。
     * - jobs/failed_jobs/job_batches/cache/cache_locks/sessions：Laravel 运行时
     * - *_refresh_tokens：JWT 刷新令牌，恢复后旧 token 不应复活，让用户重新登录
     * - domain_validation_records：证书域名验证的瞬时记录，恢复后是过时数据
     */
    public const RUNTIME_RESET_TABLES = [
        'jobs',
        'failed_jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'admin_refresh_tokens',
        'user_refresh_tokens',
        'domain_validation_records',
    ];

    public function basePath(): string
    {
        return storage_path('databak');
    }

    /**
     * 按 driver 选择对应的 BackupHandler（仅 mysql）。
     *
     * 不在 ServiceProvider 注册 binding 是因为 Handler 仅在 BackupCommand / RestoreBackupJob
     * 内使用，按需 new 反而清晰，避免 IoC 容器对 BackupService 的循环依赖。
     */
    public function makeHandler(?string $driver = null): BackupHandlerInterface
    {
        $driver = $driver ?? (string) config('database.connections.'.config('database.default').'.driver');

        return match ($driver) {
            'mysql' => app(MysqlBackupHandler::class),
            default => throw new RuntimeException("不支持的备份 driver: {$driver}（仅支持 mysql）"),
        };
    }

    /**
     * 按操作系统/容器场景给出安装命令提示，每行一条。
     * 由调用方放入 ApiResponse 的 errors 数组或 console 多行输出，避免污染 msg。
     *
     * 检测优先级：
     * 1. PHP 运行限制（open_basedir / disable_functions）— 宝塔/lnmp 默认配置最常见原因
     * 2. 平台相关的客户端安装命令（按 driver 分发）
     *
     * @param  string|null  $driver  null 时回落到 database.connections.<default>.driver；不识别时返回 mysql 提示
     * @return array<int, string>
     */
    public static function installHintLines(?string $driver = null): array
    {
        $lines = [];

        $driver = $driver ?? (string) config('database.connections.'.config('database.default').'.driver');

        // 1. 优先检查 PHP 运行时限制——若客户端已装但被 open_basedir 拦截，
        // is_executable() 静默返回 false，错误现象与"没装"一致，必须显式提示。
        $openBasedir = (string) ini_get('open_basedir');
        if ($openBasedir !== '') {
            $allowed = preg_split('/[:;]/', $openBasedir) ?: [];
            $binMarker = '/mysql/bin';
            $hasBin = false;
            foreach ($allowed as $dir) {
                $dir = rtrim(trim($dir), '/');
                if ($dir !== '' && (str_contains($dir, $binMarker) || $dir === '/usr/bin' || $dir === '/bin')) {
                    $hasBin = true;
                    break;
                }
            }
            if (! $hasBin) {
                $clientName = 'mysql';
                $lines[] = "⚠ 检测到 PHP open_basedir 限制可能阻止访问 $clientName 二进制目录。";
                $lines[] = " 当前 open_basedir: $openBasedir";
                $lines[] = " 解决：编辑该网站根目录的 .user.ini，把 $clientName bin 目录加入 open_basedir；不要修改全局 php.ini。";
                if ($driver === 'mysql' || $driver === '') {
                    $lines[] = ' 宝塔：在 .user.ini 的 open_basedir 原值末尾追加 ":/www/server/mysql/bin/:/tmp/"。';
                }
                $lines[] = ' 保存后重启该站点使用的 PHP-FPM 生效。';
            }
        }

        // 2. 检查关键函数是否被禁用——宝塔默认禁 proc_open，备份/恢复完全跑不起来
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $required = ['proc_open', 'proc_close', 'proc_get_status', 'proc_terminate'];
        $missing = array_values(array_intersect($required, $disabled));
        if ($missing) {
            $lines[] = '⚠ PHP 已禁用以下函数，备份/恢复无法工作：'.implode(', ', $missing);
            $lines[] = ' 解决：在 php.ini 的 disable_functions 中移除上述函数。';
            $lines[] = ' 宝塔面板：软件商店 → PHP 管理 → 设置 → 禁用函数，删掉对应项。';
        }

        // 3. 平台相关的客户端安装命令
        return array_merge($lines, self::installHintLinesMysql());
    }

    /**
     * @return array<int, string>
     */
    private static function installHintLinesMysql(): array
    {
        $lines = [
            '请安装 Oracle MySQL 官方 mysql-client（提供 mysqldump 与 mysql），并与目标服务端保持同系列（5.7、8.0 或 8.4）。',
        ];

        $family = PHP_OS_FAMILY;
        if ($family === 'Linux') {
            $lines[] = 'Debian/Ubuntu（先启用对应系列的 Oracle MySQL 官方仓库）：`apt-get update && apt-get install -y mysql-client`。';
            $lines[] = 'RHEL/Rocky/Alma（先启用对应系列的 Oracle MySQL 官方仓库）：`dnf install -y mysql-community-client`。';
            $lines[] = '不要使用可能实际提供 MariaDB 的 default-mysql-client。';
            $lines[] = '宝塔面板：优先使用目标 MySQL 安装自带的 /www/server/mysql/bin/mysql 与 mysqldump。';
        } elseif ($family === 'Darwin') {
            $lines[] = 'macOS：安装与目标服务端同系列的 Oracle MySQL 客户端，并把 bin 目录加入 PATH。';
        } else {
            $lines[] = '请安装同系列 Oracle MySQL 官方客户端，并确保 mysqldump/mysql 在 PATH 中。';
        }
        $lines[] = '若已安装但仍报错，运行 `php artisan tinker` 后调用 `app(\App\Services\Binary\BinaryLocator::class)->diagnose("mysqldump")` 查看候选路径与试探日志。';

        return $lines;
    }

    public function ensureDirectory(): void
    {
        $path = $this->basePath();
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("无法创建备份目录: $path");
        }
    }

    /** 动态日志表不进入备份 artifact，恢复时保留目标库现状。 @return list<string> */
    public function resolveRetainedTables(string $database): array
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.$connection.driver");

        return match ($driver) {
            'mysql' => $this->discoverLogsTablesMysql($database),
            default => [],
        };
    }

    /** @return list<string> */
    public function resolveRuntimeResetTables(): array
    {
        $tables = [];
        foreach (self::RUNTIME_RESET_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * mysql：用 information_schema 查 %_logs 表（带 schema 过滤）。
     *
     * @return array<int, string>
     */
    private function discoverLogsTablesMysql(string $database): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '%\\_logs'",
            [$database]
        );

        return array_map(fn ($r) => $r->TABLE_NAME, $rows);
    }

    /**
     * 从 DatabaseStructureService 导出的结构数组里剔除指定表名。
     */
    public function filterStructureTables(array $structure, array $ignoreTables): array
    {
        if (empty($structure['tables']) || empty($ignoreTables)) {
            return $structure;
        }
        $ignore = array_flip($ignoreTables);
        $structure['tables'] = array_diff_key($structure['tables'], $ignore);

        return $structure;
    }

    /**
     * 列出所有完整备份（按时间倒序）。检查器只接受最终 SQL 文件，天然忽略 .part。
     *
     * @return array<int, array{id:string,prefix:string,filename:string,path:string,size:int,created_at:string,has_schema:bool,schema_size:int}>
     */
    public function listBackups(): array
    {
        $this->ensureDirectory();

        $base = $this->basePath();
        $files = glob("$base/*_*.sql.gz") ?: [];

        $items = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (! preg_match('/^([a-z_]+)_(\d{8}_\d{6})\.sql\.gz$/', $filename, $m)) {
                continue;
            }

            $id = "{$m[1]}_$m[2]";
            try {
                $artifact = (new BackupArtifactInspector($base))->inspect($id, verifyContents: false);
            } catch (RuntimeException) {
                continue;
            }
            $hasSchema = $artifact['schema_path'] !== null;

            $items[] = [
                'id' => $id,
                'prefix' => $m[1],
                'filename' => $filename,
                'path' => $file,
                'size' => (int) (@filesize($file) ?: 0),
                'created_at' => date('Y-m-d H:i:s', (int) (@filemtime($file) ?: 0)),
                'has_schema' => $hasSchema,
                'schema_size' => $hasSchema ? (int) (@filesize($artifact['schema_path']) ?: 0) : 0,
            ];
        }

        usort($items, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $items;
    }

    /**
     * 校验备份 ID 格式并返回对应的文件路径；不存在返回 null。
     *
     * @return array{id:string,sql:string,schema:?string}|null
     */
    public function resolveBackup(string $id): ?array
    {
        if (! preg_match('/^[a-z_]+_\d{8}_\d{6}$/', $id)) {
            return null;
        }

        try {
            $artifact = (new BackupArtifactInspector($this->basePath()))->inspect($id, verifyContents: false);
        } catch (RuntimeException) {
            return null;
        }

        return [
            'id' => $artifact['id'],
            'sql' => $artifact['sql_path'],
            'schema' => $artifact['schema_path'],
        ];
    }

    /**
     * 删除备份（含配套 schema.json）。返回实际删除的文件数。
     */
    public function deleteBackup(string $id): int
    {
        $backup = $this->resolveBackup($id);
        if ($backup === null) {
            return 0;
        }

        $deleted = 0;
        if (@unlink($backup['sql'])) {
            $deleted++;
        }
        if ($backup['schema'] !== null && @unlink($backup['schema'])) {
            $deleted++;
        }

        return $deleted;
    }

    /**
     * 读取 schema.json 内容（已解码），无 schema 时返回 null。
     */
    public function readSchema(string $id): ?array
    {
        try {
            $artifact = (new BackupArtifactInspector($this->basePath()))->inspect($id, verifyContents: false);
        } catch (RuntimeException) {
            return null;
        }

        return $artifact['schema'];
    }

    // ----- 异步 Job 进度 -----

    public function newJobToken(): string
    {
        return Str::random(32);
    }

    public function setJobProgress(string $token, array $payload): void
    {
        Cache::put(self::JOB_CACHE_PREFIX.$token, $payload, self::JOB_CACHE_TTL);
    }

    public function getJobProgress(string $token): ?array
    {
        return Cache::get(self::JOB_CACHE_PREFIX.$token);
    }

    // ----- 下载 token -----

    /**
     * 签发一次性下载 token（TTL 5 分钟）。
     */
    public function issueDownloadToken(string $backupId, int $adminId): string
    {
        if ($this->resolveBackup($backupId) === null) {
            throw new RuntimeException("备份不存在: $backupId");
        }

        $token = Str::random(40);
        Cache::put(self::DOWNLOAD_TOKEN_PREFIX.$token, [
            'backup_id' => $backupId,
            'admin_id' => $adminId,
            'issued_at' => time(),
        ], self::DOWNLOAD_TOKEN_TTL);

        return $token;
    }

    /**
     * 核销下载 token，返回绑定的 backup_id；无效/过期返回 null。
     * 核销后 token 立即失效（一次性）。
     */
    public function consumeDownloadToken(string $token): ?string
    {
        $key = self::DOWNLOAD_TOKEN_PREFIX.$token;
        $payload = Cache::get($key);
        if (! is_array($payload) || empty($payload['backup_id'])) {
            return null;
        }
        Cache::forget($key);

        return (string) $payload['backup_id'];
    }
}
