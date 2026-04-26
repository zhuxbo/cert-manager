<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * 数据库备份服务：列表/删除/进度读写/下载 token。
 *
 * 产物约定：
 *   {prefix}_YYYYMMDD_HHMMSS.sql.gz         — mysqldump + gzip
 *   {prefix}_YYYYMMDD_HHMMSS.schema.json    — 当前数据库结构（同名异后缀）
 * prefix：
 *   backup       — 常规备份（受 --keep 清理）
 *   pre_restore  — 恢复前自动保险备份（永不自动清理）
 */
class BackupService
{
    /** 进度信息 Cache key 前缀 */
    public const JOB_CACHE_PREFIX = 'backup:job:';

    /** 互斥锁 Cache key（create/restore 共享，保证同一时刻只有一个） */
    public const MUTEX_LOCK_KEY = 'backup:mutex';

    /** 下载 token Cache key 前缀 */
    public const DOWNLOAD_TOKEN_PREFIX = 'backup:download:';

    /** 下载 token 有效期（秒） */
    public const DOWNLOAD_TOKEN_TTL = 300;

    /** Job 进度信息保留时长（秒） */
    public const JOB_CACHE_TTL = 3600;

    /**
     * 备份时固定排除的运行时表（日志表通过 %_logs 动态发现）。
     * BackupCommand 的 mysqldump、schema.json 写入、Controller 的 schemaDiff 共用同一份。
     *  - jobs/failed_jobs/job_batches/cache/cache_locks/sessions：Laravel 运行时
     *  - *_refresh_tokens：JWT 刷新令牌，恢复后旧 token 不应复活，让用户重新登录
     *  - domain_validation_records：证书域名验证的瞬时记录，恢复后是过时数据
     */
    public const EXCLUDED_TABLES = [
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
     * 确认 mysql 客户端二进制可用：
     *   - 绝对路径 → is_executable
     *   - 相对名 → 走 PATH 查找
     * 找不到时抛 RuntimeException，message 里附带按平台区分的安装指引。
     *
     * @param  string  $tool  'mysqldump' 或 'mysql'
     */
    public function ensureMysqlClient(string $tool): string
    {
        $configured = $tool === 'mysqldump'
            ? (string) config('database.backup.mysqldump_bin', 'mysqldump')
            : (string) config('database.backup.mysql_bin', 'mysql');

        // 已是绝对/相对路径 → 直接判断可执行
        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            if (is_executable($configured)) {
                return $configured;
            }
            throw new RuntimeException(
                "{$tool} 不可执行: $configured\n".self::installHint($tool)
            );
        }

        // 相对名 → ExecutableFinder 走 PATH + 一批常见安装目录兜底
        // 关键：PHP-FPM / queue worker 进程的 PATH 通常只有 /usr/bin:/bin，
        // 不会继承终端 PATH，因此 brew/MySQL 官方安装的目录必须显式列入。
        $extraDirs = [
            // macOS Homebrew (Apple Silicon)
            '/opt/homebrew/bin',
            '/opt/homebrew/opt/mysql-client/bin',
            '/opt/homebrew/opt/mysql/bin',
            // macOS Homebrew (Intel)
            '/usr/local/bin',
            '/usr/local/opt/mysql-client/bin',
            '/usr/local/opt/mysql/bin',
            // macOS 官方 dmg
            '/usr/local/mysql/bin',
            // Linux 自编译/官方 tar 常见位置
            '/opt/mysql/bin',
        ];

        $found = (new ExecutableFinder)->find($configured, null, $extraDirs);
        if ($found !== null) {
            return $found;
        }

        throw new RuntimeException(
            "未找到 $tool 命令（在 PATH 中查找 $configured 失败）\n".self::installHint($tool)
        );
    }

    /**
     * 按操作系统/容器场景给出安装命令提示。
     */
    private static function installHint(string $tool): string
    {
        $isDocker = is_file('/.dockerenv') || is_dir('/proc/1/cgroup') && @str_contains((string) @file_get_contents('/proc/1/cgroup'), 'docker');

        $lines = ['请安装 mysql 客户端工具（提供 mysqldump 与 mysql 两个命令）后重试。'];

        if ($isDocker) {
            $lines[] = '检测到运行在 Docker 容器中，请在 Dockerfile 中加入：';
            $lines[] = '  Alpine 镜像:  RUN apk add --no-cache mysql-client';
            $lines[] = '  Debian 镜像:  RUN apt-get update && apt-get install -y default-mysql-client';
            $lines[] = '修改后重新构建镜像并部署。';
        } else {
            $family = PHP_OS_FAMILY;
            if ($family === 'Linux') {
                $lines[] = '安装命令：';
                $lines[] = '  Alpine:        apk add mysql-client';
                $lines[] = '  Debian/Ubuntu: apt install default-mysql-client';
                $lines[] = '  RHEL/CentOS:   yum install mysql';
            } elseif ($family === 'Darwin') {
                $lines[] = 'macOS 安装命令： brew install mysql-client';
                $lines[] = '安装后将 mysql-client 的 bin 路径加入 PATH，或在 .env 中设置 MYSQLDUMP_BIN/MYSQL_BIN 绝对路径。';
            } else {
                $lines[] = '请安装 MySQL 官方客户端工具，并确保 mysqldump/mysql 在 PATH 中。';
            }
            $lines[] = '或在 .env 中显式指定路径：MYSQLDUMP_BIN=/path/to/mysqldump、MYSQL_BIN=/path/to/mysql';
        }

        return implode("\n", $lines);
    }

    public function ensureDirectory(): void
    {
        $path = $this->basePath();
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("无法创建备份目录: $path");
        }
    }

    /**
     * 解析当前数据库中需要从备份/对比中排除的表：%_logs 动态发现 + EXCLUDED_TABLES。
     */
    public function resolveIgnoreTables(string $database): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '%\\_logs'",
            [$database]
        );
        $tables = array_map(fn ($r) => $r->TABLE_NAME, $rows);

        foreach (self::EXCLUDED_TABLES as $t) {
            $exists = DB::select(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
                [$database, $t]
            );
            if (! empty($exists)) {
                $tables[] = $t;
            }
        }

        return array_values(array_unique($tables));
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
     * 列出所有备份文件（按时间倒序），每条包含 sql.gz 与配套 schema.json 信息。
     *
     * @return array<int, array{id:string,prefix:string,filename:string,path:string,size:int,created_at:string,has_schema:bool,schema_size:int}>
     */
    public function listBackups(): array
    {
        $this->ensureDirectory();

        $files = glob($this->basePath().'/*_*.sql.gz') ?: [];
        $items = [];

        foreach ($files as $file) {
            $filename = basename($file);
            if (! preg_match('/^([a-z_]+)_(\d{8}_\d{6})\.sql\.gz$/', $filename, $m)) {
                continue;
            }

            $prefix = $m[1];
            $stamp = $m[2];
            $id = $prefix.'_'.$stamp; // 备份 ID = 文件名去扩展名
            $schemaFile = preg_replace('/\.sql\.gz$/', '.schema.json', $file);
            $hasSchema = $schemaFile !== null && is_file($schemaFile);

            $items[] = [
                'id' => $id,
                'prefix' => $prefix,
                'filename' => $filename,
                'path' => $file,
                'size' => (int) (@filesize($file) ?: 0),
                'created_at' => date('Y-m-d H:i:s', (int) (@filemtime($file) ?: 0)),
                'has_schema' => $hasSchema,
                'schema_size' => $hasSchema ? (int) (@filesize($schemaFile) ?: 0) : 0,
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

        $sql = $this->basePath().'/'.$id.'.sql.gz';
        if (! is_file($sql)) {
            return null;
        }

        $schema = $this->basePath().'/'.$id.'.schema.json';
        if (! is_file($schema)) {
            $schema = null;
        }

        return [
            'id' => $id,
            'sql' => $sql,
            'schema' => $schema,
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
        $backup = $this->resolveBackup($id);
        if ($backup === null || $backup['schema'] === null) {
            return null;
        }

        $content = @file_get_contents($backup['schema']);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
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
