<?php

namespace App\Services\Upgrade;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

class BackupManager
{
    protected string $backupPath;

    protected int $maxBackups;

    public function __construct()
    {
        $this->backupPath = Config::get('upgrade.backup.path', storage_path('backups'));
        $this->maxBackups = Config::get('upgrade.backup.max_backups', 5);

        // 确保备份目录存在
        if (! File::isDirectory($this->backupPath)) {
            File::makeDirectory($this->backupPath, 0755, true);
        }
    }

    /**
     * 创建备份
     *
     * @return string 备份 ID
     */
    public function createBackup(): string
    {
        $backupId = date('Y-m-d_His');
        $backupDir = "$this->backupPath/$backupId";

        // 创建备份目录
        File::makeDirectory($backupDir, 0755, true);

        try {
            $includeConfig = Config::get('upgrade.backup.include', []);

            // 备份后端代码
            if ($includeConfig['backend'] ?? true) {
                $this->backupBackend($backupDir);
            }

            // 备份前端代码
            if ($includeConfig['frontend'] ?? true) {
                $this->backupFrontend($backupDir);
            }

            // 备份数据库
            if ($includeConfig['database'] ?? false) {
                $this->backupDatabase($backupDir);
            }

            // 记录备份信息
            $this->saveBackupInfo($backupDir, $backupId);

            // 清理旧备份
            $this->cleanOldBackups();

            Log::info("备份创建成功: $backupId");

            return $backupId;
        } catch (\Exception $e) {
            // 清理失败的备份
            if (File::isDirectory($backupDir)) {
                File::deleteDirectory($backupDir);
            }
            Log::error("备份创建失败: {$e->getMessage()}");
            throw new RuntimeException("备份创建失败: {$e->getMessage()}");
        }
    }

    /**
     * 列出备份
     */
    public function listBackups(int $limit = 5): array
    {
        $backups = [];
        $dirs = File::directories($this->backupPath);

        foreach ($dirs as $dir) {
            $infoFile = "$dir/backup.json";
            if (File::exists($infoFile)) {
                $info = json_decode(File::get($infoFile), true);
                if ($info) {
                    $info['path'] = $dir;
                    $info['size'] = $this->getDirectorySize($dir);
                    $backups[] = $info;
                }
            }
        }

        // 按时间倒序排列
        usort($backups, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        return $limit > 0 ? array_slice($backups, 0, $limit) : $backups;
    }

    /**
     * 验证备份 ID 格式（防止路径遍历）
     * 支持两种格式：
     * - PHP API: 2026-01-15_021459
     * - upgrade.sh: 20260114_180232
     */
    protected function validateBackupId(string $backupId): void
    {
        $phpFormat = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';
        $shellFormat = '/^\d{8}_\d{6}$/';

        if (! preg_match($phpFormat, $backupId) && ! preg_match($shellFormat, $backupId)) {
            throw new RuntimeException("无效的备份 ID: $backupId");
        }
    }

    /**
     * 获取备份信息
     */
    public function getBackup(string $backupId): ?array
    {
        $this->validateBackupId($backupId);
        $backupDir = "$this->backupPath/$backupId";
        $infoFile = "$backupDir/backup.json";

        if (! File::exists($infoFile)) {
            return null;
        }

        $info = json_decode(File::get($infoFile), true);
        if ($info) {
            $info['path'] = $backupDir;
            $info['size'] = $this->getDirectorySize($backupDir);
        }

        return $info;
    }

    /**
     * 恢复备份
     */
    public function restoreBackup(string $backupId): bool
    {
        $this->validateBackupId($backupId);
        $backupDir = "$this->backupPath/$backupId";

        if (! File::isDirectory($backupDir)) {
            throw new RuntimeException("备份不存在: $backupId");
        }

        try {
            $infoFile = "$backupDir/backup.json";
            $info = json_decode(File::get($infoFile), true);

            // 恢复后端代码
            if ($info['includes']['backend'] ?? false) {
                $this->restoreBackend($backupDir);
            }

            // 恢复前端代码
            if ($info['includes']['frontend'] ?? false) {
                $this->restoreFrontend($backupDir);
            }

            // 不自动恢复数据库（避免数据丢失风险）
            // 数据库备份仍然保留，供需要时手动恢复

            Log::info("备份恢复成功: $backupId");

            return true;
        } catch (\Exception $e) {
            Log::error("备份恢复失败: {$e->getMessage()}");
            throw new RuntimeException("备份恢复失败: {$e->getMessage()}");
        }
    }

    /**
     * 删除备份
     */
    public function deleteBackup(string $backupId): bool
    {
        $this->validateBackupId($backupId);
        $backupDir = "$this->backupPath/$backupId";

        if (File::isDirectory($backupDir)) {
            File::deleteDirectory($backupDir);
            Log::info("备份已删除: $backupId");

            return true;
        }

        return false;
    }

    /**
     * 清理旧备份
     */
    public function cleanOldBackups(): int
    {
        $backups = $this->listBackups(0);
        $deleted = 0;

        if (count($backups) > $this->maxBackups) {
            $toDelete = array_slice($backups, $this->maxBackups);
            foreach ($toDelete as $backup) {
                $backupId = $backup['id'] ?? '';
                if ($backupId && $this->deleteBackup($backupId)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * 备份后端代码
     */
    protected function backupBackend(string $backupDir): void
    {
        $backendPath = base_path();
        $zipFile = "$backupDir/backend.zip";

        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建备份压缩文件');
        }

        // 要备份的目录
        $dirs = ['app', 'config', 'database', 'routes', 'bootstrap'];

        foreach ($dirs as $dir) {
            $fullPath = "$backendPath/$dir";
            if (File::isDirectory($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $dir);
            }
        }

        // 备份重要文件
        $files = ['composer.json', 'composer.lock', '.env'];
        foreach ($files as $file) {
            $fullPath = "$backendPath/$file";
            if (File::exists($fullPath)) {
                $zip->addFile($fullPath, $file);
            }
        }

        // 备份项目根目录的 version.json
        $rootDir = dirname($backendPath);
        $versionPath = "$rootDir/version.json";
        if (File::exists($versionPath)) {
            $zip->addFile($versionPath, '../version.json');
        }

        $zip->close();
    }

    /**
     * 备份前端代码
     */
    protected function backupFrontend(string $backupDir): void
    {
        $projectRoot = dirname(base_path());
        $frontendPath = "$projectRoot/frontend";

        if (! File::isDirectory($frontendPath)) {
            Log::warning('前端目录不存在，跳过备份');

            return;
        }

        $zipFile = "$backupDir/frontend.zip";

        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建前端备份压缩文件');
        }

        // 备份前端应用（生产环境直接部署，没有 dist 子目录）
        $apps = ['admin', 'user', 'web'];
        $hasContent = false;

        foreach ($apps as $app) {
            $appPath = "$frontendPath/$app";
            if (File::isDirectory($appPath)) {
                $this->addDirectoryToZip($zip, $appPath, $app);
                $hasContent = true;
            }
        }

        $zip->close();

        // 如果没有内容，删除空 zip
        if (! $hasContent) {
            File::delete($zipFile);
            Log::warning('前端应用目录不存在，跳过备份');
        }
    }

    /**
     * 备份数据库
     */
    protected function backupDatabase(string $backupDir): void
    {
        $connection = Config::get('database.default');
        $database = Config::get("database.connections.$connection.database");
        $sqlFile = "$backupDir/database.sql";

        if (in_array($connection, ['mysql', 'mariadb'], true)) {
            $this->backupMysql($sqlFile, $database);
        } else {
            Log::warning("不支持的数据库类型备份: {$connection}（仅支持 mysql）");
        }
    }

    /**
     * 备份 MySQL 数据库
     */
    protected function backupMysql(string $sqlFile, string $database): void
    {
        $host = Config::get('database.connections.mysql.host');
        $port = Config::get('database.connections.mysql.port', 3306);
        $username = Config::get('database.connections.mysql.username');
        $password = Config::get('database.connections.mysql.password');

        // 构建排除表参数
        $excludeTables = Config::get('upgrade.backup.exclude_tables', []);

        // 动态追加所有 _logs 后缀表（插件日志表等）
        try {
            $allTables = DB::select("SHOW TABLES LIKE '%\\_logs'");
            foreach ($allTables as $row) {
                $tableName = current((array) $row);
                if (! preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                    continue;
                }
                if (! in_array($tableName, $excludeTables)) {
                    $excludeTables[] = $tableName;
                }
            }
        } catch (\Throwable) {
            // ignore
        }
        $ignoreArgs = '';
        foreach ($excludeTables as $table) {
            $ignoreArgs .= sprintf(' --ignore-table=%s', escapeshellarg("$database.$table"));
        }

        // 使用环境变量传递密码，避免在进程列表中暴露
        $command = sprintf(
            'MYSQL_PWD=%s mysqldump --host=%s --port=%d --user=%s%s %s > %s 2>&1',
            escapeshellarg($password),
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            $ignoreArgs,
            escapeshellarg($database),
            escapeshellarg($sqlFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('MySQL 备份失败: '.implode("\n", $output));
        }

        Log::info('MySQL 备份完成', ['excluded_tables' => $excludeTables]);
    }

    /**
     * 恢复后端代码
     */
    protected function restoreBackend(string $backupDir): void
    {
        $zipFile = "$backupDir/backend.zip";

        if (! File::exists($zipFile)) {
            throw new RuntimeException('后端备份文件不存在');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('无法打开备份压缩文件');
        }

        $basePath = base_path();
        $projectRoot = dirname($basePath);

        // 逐个解压文件，处理特殊路径（权限受限文件使用 File::put）
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // 根目录 version.json 特殊处理
            if ($filename === '../version.json') {
                $content = $zip->getFromIndex($i);
                File::put("$projectRoot/version.json", $content);
            } elseif ($filename === '.env') {
                // .env 文件特殊处理（可能有权限限制）
                $content = $zip->getFromIndex($i);
                File::put("$basePath/.env", $content);
            } else {
                // 其他文件解压到 base_path
                $zip->extractTo($basePath, $filename);
            }
        }

        $zip->close();
    }

    /**
     * 恢复前端代码
     */
    protected function restoreFrontend(string $backupDir): void
    {
        $zipFile = "$backupDir/frontend.zip";

        if (! File::exists($zipFile)) {
            Log::warning('前端备份文件不存在，跳过恢复');

            return;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('无法打开前端备份压缩文件');
        }

        $projectRoot = dirname(base_path());
        $frontendPath = "$projectRoot/frontend";

        // 确保前端目录存在
        if (! File::isDirectory($frontendPath)) {
            File::makeDirectory($frontendPath, 0755, true);
        }

        $zip->extractTo($frontendPath);
        $zip->close();
    }

    /**
     * 恢复数据库（仅 mysql）
     */
    protected function restoreDatabase(string $backupDir): void
    {
        $connection = Config::get('database.default');

        if (in_array($connection, ['mysql', 'mariadb'], true)) {
            $sqlFile = "$backupDir/database.sql";
            if (File::exists($sqlFile)) {
                $this->restoreMysql($sqlFile);
            }
        }
    }

    /**
     * 恢复 MySQL 数据库
     */
    protected function restoreMysql(string $sqlFile): void
    {
        $host = Config::get('database.connections.mysql.host');
        $port = Config::get('database.connections.mysql.port', 3306);
        $username = Config::get('database.connections.mysql.username');
        $password = Config::get('database.connections.mysql.password');
        $database = Config::get('database.connections.mysql.database');

        // 使用环境变量传递密码，避免在进程列表中暴露
        $command = sprintf(
            'MYSQL_PWD=%s mysql --host=%s --port=%d --user=%s %s < %s 2>&1',
            escapeshellarg($password),
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($sqlFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('MySQL 恢复失败: '.implode("\n", $output));
        }
    }

    /**
     * 保存备份信息
     */
    protected function saveBackupInfo(string $backupDir, string $backupId): void
    {
        $includeConfig = Config::get('upgrade.backup.include', []);

        $info = [
            'id' => $backupId,
            'version' => $this->readVersionFromFile(),
            'created_at' => date('c'),
            'includes' => [
                'backend' => $includeConfig['backend'] ?? true,
                'database' => $includeConfig['database'] ?? true,
                'frontend' => $includeConfig['frontend'] ?? false,
            ],
        ];

        File::put("$backupDir/backup.json", json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 直接从 version.json 读取版本号
     * 避免使用 Config::get 因为配置缓存可能导致版本号不准确
     */
    protected function readVersionFromFile(): string
    {
        $versionPaths = [
            dirname(base_path()).'/version.json', // 项目根目录（标准部署）
            base_path('version.json'), // backend 目录（兼容旧版本路径）
        ];

        foreach ($versionPaths as $path) {
            if (File::exists($path)) {
                $content = File::get($path);
                $data = json_decode($content, true);
                if (! empty($data['version'])) {
                    return $data['version'];
                }
            }
        }

        return 'unknown';
    }

    /**
     * 添加目录到 ZIP
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $path, string $relativePath): void
    {
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $zipPath = $relativePath.'/'.$file->getRelativePathname();
            $zip->addFile($filePath, $zipPath);
        }
    }

    /**
     * 获取目录大小
     */
    protected function getDirectorySize(string $path): int
    {
        $size = 0;
        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }
}
