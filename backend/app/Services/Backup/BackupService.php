<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * 数据库备份服务：列表/删除/进度读写/下载 token。
 *
 * 产物约定：
 * {prefix}_YYYYMMDD_HHMMSS.sql.gz — mysqldump + gzip
 * {prefix}_YYYYMMDD_HHMMSS.schema.json — 当前数据库结构（同名异后缀）
 * prefix：
 * backup — 常规备份（受 --keep 清理）
 * pre_restore — 恢复前自动保险备份（永不自动清理）
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
     * 加密文件 magic（4 bytes）— SSL Manager Backup Encrypted。
     * 对照：未加密 gzip 文件以 0x1f 0x8b 开头，与 'SBME' 完全不冲突，可用于版本嗅探。
     */
    public const ENC_MAGIC = 'SBME';

    /** 加密格式版本号（1 byte） */
    public const ENC_VERSION = 0x01;

    /** 加密算法 ID（1 byte）— 0x01 = aes-256-cbc */
    public const ENC_CIPHER_AES256_CBC = 0x01;

    /** Header 长度：4 magic + 1 version + 1 cipher_id + 16 IV = 22 bytes */
    public const ENC_HEADER_LENGTH = 22;

    /**
     * 备份时固定排除的运行时表（日志表通过 %_logs 动态发现）。
     * BackupCommand 的 mysqldump、schema.json 写入、Controller 的 schemaDiff 共用同一份。
     * - jobs/failed_jobs/job_batches/cache/cache_locks/sessions：Laravel 运行时
     * - *_refresh_tokens：JWT 刷新令牌，恢复后旧 token 不应复活，让用户重新登录
     * - domain_validation_records：证书域名验证的瞬时记录，恢复后是过时数据
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

    /** 候选 mysql 客户端二进制目录（按宝塔/Linux 发行版/macOS Homebrew 顺序） */
    private const CANDIDATE_BIN_DIRS = [
        // 宝塔面板默认 MySQL 安装路径（www 用户的 PATH 不含此目录，且大概率被 open_basedir 锁外）
        '/www/server/mysql/bin',
        // Linux 包管理器 / 自编译
        '/usr/bin',
        '/usr/local/bin',
        '/usr/local/mysql/bin',
        '/opt/mysql/bin',
        // macOS Homebrew (Apple Silicon)
        '/opt/homebrew/bin',
        '/opt/homebrew/opt/mysql-client/bin',
        '/opt/homebrew/opt/mysql/bin',
        // macOS Homebrew (Intel)
        '/usr/local/opt/mysql-client/bin',
        '/usr/local/opt/mysql/bin',
    ];

    /**
     * 确认 mysql 客户端二进制可用，返回真实路径。
     *
     * 探测策略（按顺序）：
     * 1. 配置含路径分隔符（绝对/相对路径）→ 直接 proc_open 验证
     * 2. 配置是相对名 → ExecutableFinder 走 PATH（成本低，先试）
     * 3. PATH 查不到 → 遍历 CANDIDATE_BIN_DIRS，逐个 proc_open --version 探测
     *
     * 关键：**全程不用 is_executable / file_exists**，因为它们受 open_basedir 限制
     * （宝塔站点默认把 /www/server/mysql/bin/ 锁在白名单外，这两个调用会静默 false）。
     * 而 proc_open 不在 open_basedir 检查列表，可直接启动子进程探测。
     *
     * 找不到时抛 RuntimeException，message 仅含简短失败原因；详细安装指引由调用方
     * 通过 {@see installHintLines()} 拼到响应 errors 字段。
     *
     * @param  string  $tool  'mysqldump' 或 'mysql'
     */
    public function ensureMysqlClient(string $tool): string
    {
        $configured = $tool === 'mysqldump'
        ? (string) config('database.backup.mysqldump_bin', 'mysqldump')
        : (string) config('database.backup.mysql_bin', 'mysql');

        // 1. 含路径分隔符 → 视为绝对/相对路径，直接 proc_open 验证（不能用 is_executable）
        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            if (self::probeExecutable($configured)) {
                return $configured;
            }
            throw new RuntimeException("$tool 不可执行: $configured");
        }

        // 2. 相对名 → 先走 PATH（覆盖正常环境，几乎无成本）
        $found = (new ExecutableFinder)->find($configured);
        if ($found !== null && self::probeExecutable($found)) {
            return $found;
        }

        // 3. PATH 失败（多见于 open_basedir 限制）→ 遍历候选目录用 proc_open 探测
        foreach (self::CANDIDATE_BIN_DIRS as $dir) {
            $candidate = "$dir/$configured";
            if (self::probeExecutable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("未找到 $tool 命令");
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
            'mysql', 'mariadb' => new MysqlBackupHandler($this),
            default => throw new RuntimeException("不支持的备份 driver: {$driver}（仅支持 mysql）"),
        };
    }

    /**
     * 用 proc_open 启动 `$path --version` 验证二进制是否可执行。
     *
     * 必须用 array 形式调用，避免 shell 解释；array 形式 PHP 内部走 execve，
     * 不受 open_basedir 影响，且天然防注入（不会展开变量/通配符）。
     *
     * 返回 true 仅当：proc_open 启动成功 + 退出码 0 + stdout 含 "Distrib"/"Ver "（mysql 客户端 --version 输出特征）。
     */
    private static function probeExecutable(string $path): bool
    {
        $proc = @proc_open(
            [$path, '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($proc)) {
            return false;
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        // mysql/mysqldump --version 输出特征，避免误识别同名占位文件
        return $exit === 0 && (str_contains($stdout, 'Distrib') || str_contains($stdout, 'Ver '));
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
                $lines[] = " 解决：在站点 PHP 配置里把 $clientName bin 目录加入 open_basedir。";
                if ($driver === 'mysql' || $driver === '') {
                    $lines[] = ' 宝塔面板：网站 → 设置 → 配置文件，在 php_admin_value[open_basedir] 行末追加 ":/www/server/mysql/bin/:/tmp/"';
                }
                $lines[] = ' 修改后重启 php-fpm 生效。';
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
        $lines = ['请确认已安装 mysql 客户端工具（提供 mysqldump 与 mysql 两个命令）。'];

        $family = PHP_OS_FAMILY;
        if ($family === 'Linux') {
            $lines[] = '安装命令：';
            $lines[] = ' Debian/Ubuntu: apt install default-mysql-client';
            $lines[] = ' RHEL/CentOS: yum install mysql';
            $lines[] = '宝塔面板：自带 mysql-client，已将 /www/server/mysql/bin 加入查找路径。';
        } elseif ($family === 'Darwin') {
            $lines[] = 'macOS 安装命令： brew install mysql-client';
            $lines[] = '安装后将 mysql-client 的 bin 路径加入 PATH，或在 .env 中设置 MYSQLDUMP_BIN/MYSQL_BIN 绝对路径。';
        } else {
            $lines[] = '请安装 MySQL 官方客户端工具，并确保 mysqldump/mysql 在 PATH 中。';
        }
        $lines[] = '若已安装但仍报错，请在 .env 中显式指定路径：';
        $lines[] = ' MYSQLDUMP_BIN=/path/to/mysqldump';
        $lines[] = ' MYSQL_BIN=/path/to/mysql';

        return $lines;
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
     *
     * 仅 mysql：information_schema.TABLES。
     */
    public function resolveIgnoreTables(string $database): array
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.$connection.driver");

        $tables = match ($driver) {
            'mysql', 'mariadb' => $this->discoverLogsTablesMysql($database),
            default => [],
        };

        foreach (self::EXCLUDED_TABLES as $t) {
            if (Schema::hasTable($t)) {
                $tables[] = $t;
            }
        }

        return array_values(array_unique($tables));
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
     * 列出所有备份文件（按时间倒序），每条包含 sql.gz / sql.gz.enc 与配套 schema.json 信息。
     *
     * 6-1 之后默认产出 *.sql.gz.enc（加密）；保留对存量 *.sql.gz 的识别（未加密旧备份）。
     * 同 ID（prefix_timestamp）若同时存在 .enc 与 .sql.gz，优先取 .enc（视为最新加密版）。
     *
     * @return array<int, array{id:string,prefix:string,filename:string,path:string,size:int,encrypted:bool,created_at:string,has_schema:bool,schema_size:int}>
     */
    public function listBackups(): array
    {
        $this->ensureDirectory();

        $base = $this->basePath();
        $files = array_merge(
            glob("$base/*_*.sql.gz.enc") ?: [],
            glob("$base/*_*.sql.gz") ?: [],
        );

        // 按 ID 收口；遇到同一 ID 已存在则跳过（顺序保证 .enc 先入）
        $byId = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (! preg_match('/^([a-z_]+)_(\d{8}_\d{6})\.sql\.gz(\.enc)?$/', $filename, $m)) {
                continue;
            }

            $prefix = $m[1];
            $stamp = $m[2];
            $isEnc = ($m[3] ?? '') === '.enc';
            $id = "{$prefix}_$stamp";
            if (isset($byId[$id])) {
                continue; // 同 ID 已记录（优先 .enc）
            }

            $schemaFile = "$base/$id.schema.json";
            $hasSchema = is_file($schemaFile);

            $byId[$id] = [
                'id' => $id,
                'prefix' => $prefix,
                'filename' => $filename,
                'path' => $file,
                'size' => (int) (@filesize($file) ?: 0),
                'encrypted' => $isEnc,
                'created_at' => date('Y-m-d H:i:s', (int) (@filemtime($file) ?: 0)),
                'has_schema' => $hasSchema,
                'schema_size' => $hasSchema ? (int) (@filesize($schemaFile) ?: 0) : 0,
            ];
        }

        $items = array_values($byId);
        usort($items, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $items;
    }

    /**
     * 校验备份 ID 格式并返回对应的文件路径；不存在返回 null。
     *
     * 优先返回 .sql.gz.enc（加密），缺失时回落到 .sql.gz（兼容存量未加密备份）。
     *
     * @return array{id:string,sql:string,encrypted:bool,schema:?string}|null
     */
    public function resolveBackup(string $id): ?array
    {
        if (! preg_match('/^[a-z_]+_\d{8}_\d{6}$/', $id)) {
            return null;
        }

        $base = $this->basePath();
        $encPath = "$base/$id.sql.gz.enc";
        $plainPath = "$base/$id.sql.gz";

        if (is_file($encPath)) {
            $sql = $encPath;
            $encrypted = true;
        } elseif (is_file($plainPath)) {
            $sql = $plainPath;
            $encrypted = false;
        } else {
            return null;
        }

        $schemaPath = "$base/$id.schema.json";
        $schema = is_file($schemaPath) ? $schemaPath : null;

        return [
            'id' => $id,
            'sql' => $sql,
            'encrypted' => $encrypted,
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

    // ----- 备份加密 / 解密 -----

    /**
     * 把 BACKUP_ENC_KEY (hex) 解为 32 字节二进制密钥。
     * 缺失或长度不对一律抛 RuntimeException — 默认启用，未配置即视为部署错误。
     */
    private static function loadEncKey(): string
    {
        $hex = (string) config('backup.enc_key', '');
        if ($hex === '') {
            throw new RuntimeException(
                'BACKUP_ENC_KEY 未配置：备份加密默认启用。'
                .' 请在 .env 写入 BACKUP_ENC_KEY=$(openssl rand -hex 32)，'
                .'丢失=备份不可恢复，请离线保存。'
            );
        }
        // 严格 64 hex 字符（AES-256 = 32 字节）
        if (! preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new RuntimeException('BACKUP_ENC_KEY 必须是 64 个十六进制字符（32 字节 / AES-256 密钥）');
        }
        $key = hex2bin($hex);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_ENC_KEY 解码失败');
        }

        return $key;
    }

    /**
     * 加密备份文件。
     *
     * 输出文件路径 = $sourcePath . '.enc'（同目录加 .enc 后缀），原文件保留供调用方决定是否删除。
     * 格式（二进制）：
     * [4 bytes magic 'SBME'][1 byte version=0x01][1 byte cipher_id=0x01][16 bytes IV][N bytes ciphertext]
     *
     * 缺失 BACKUP_ENC_KEY → RuntimeException。
     *
     * @param  string  $sourcePath  原始（未加密）文件路径
     * @return string 加密后文件路径
     */
    public function encryptBackup(string $sourcePath): string
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("源文件不存在: $sourcePath");
        }
        $key = self::loadEncKey();

        $plaintext = file_get_contents($sourcePath);
        if ($plaintext === false) {
            throw new RuntimeException("无法读取源文件: $sourcePath");
        }

        $iv = random_bytes(16); // AES-256-CBC IV = 16 bytes
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('备份加密失败: '.(string) openssl_error_string());
        }

        $header = self::ENC_MAGIC
        .chr(self::ENC_VERSION)
        .chr(self::ENC_CIPHER_AES256_CBC)
        .$iv;

        $destPath = $sourcePath.'.enc';
        if (file_put_contents($destPath, $header.$cipher) === false) {
            throw new RuntimeException("无法写入加密文件: $destPath");
        }
        @chmod($destPath, 0600);

        return $destPath;
    }

    /**
     * 解密备份文件到指定目标路径。
     *
     * 校验失败（magic/版本/cipher_id 不支持，或解密失败）→ RuntimeException。
     *
     * @param  string  $encPath  加密文件路径
     * @param  string  $destPath  解密输出路径
     */
    public function decryptBackup(string $encPath, string $destPath): void
    {
        if (! is_file($encPath)) {
            throw new RuntimeException("加密文件不存在: $encPath");
        }
        $key = self::loadEncKey();

        $blob = file_get_contents($encPath);
        if ($blob === false) {
            throw new RuntimeException("无法读取加密文件: $encPath");
        }
        if (strlen($blob) < self::ENC_HEADER_LENGTH) {
            throw new RuntimeException('加密文件过短：缺少完整 header');
        }

        $magic = substr($blob, 0, 4);
        if ($magic !== self::ENC_MAGIC) {
            throw new RuntimeException('加密文件格式错误：magic 不匹配（期望 SBME）');
        }

        $version = ord($blob[4]);
        if ($version !== self::ENC_VERSION) {
            throw new RuntimeException("不支持的加密格式版本: $version");
        }

        $cipherId = ord($blob[5]);
        if ($cipherId !== self::ENC_CIPHER_AES256_CBC) {
            throw new RuntimeException("不支持的加密算法 ID: $cipherId");
        }

        $iv = substr($blob, 6, 16);
        $cipher = substr($blob, self::ENC_HEADER_LENGTH);

        $plaintext = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('备份解密失败：密钥不匹配或文件损坏');
        }

        if (file_put_contents($destPath, $plaintext) === false) {
            throw new RuntimeException("无法写入解密文件: $destPath");
        }
    }
}
