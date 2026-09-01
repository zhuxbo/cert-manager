<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupMetadataFactory;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseOperationMutex;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\PipelineResult;
use App\Services\Notification\SystemAlert;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'schedule:backup
 {--keep= : 保留天数，0 表示不清理；默认读 config("database.backup.keep_days")}
 {--path= : 输出目录，默认 storage/databak}';

    protected $description = '原子备份 MySQL 数据库，剔除日志与队列等运行时表';

    private const DEDUPE_LOCK_CONTENTION = 'backup_lock_contention';

    private const DEDUPE_CLIENT_MISSING = 'backup_client_missing';

    private const DEDUPE_DUMP_ERROR = 'backup_dump_error';

    public function __construct(
        private readonly DatabaseStructureService $structureService,
        private readonly BackupService $backupService,
        private readonly BackupMetadataFactory $metadataFactory,
        private readonly MysqlToolchainChecker $toolchainChecker,
        private readonly DatabaseOperationMutex $mutex,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.$connection", []);
        $driver = (string) ($config['driver'] ?? '');
        if ($driver !== 'mysql') {
            $this->error("不支持的数据库驱动: {$driver}（仅支持 Oracle MySQL）");

            return CommandAlias::FAILURE;
        }

        if (! $this->mutex->acquire()) {
            app(SystemAlert::class)->send(
                'backup',
                '定时备份跳过（互斥）',
                '已有备份/恢复任务执行中，本次备份未执行',
                [],
                self::DEDUPE_LOCK_CONTENTION,
                24,
            );
            $this->error('已有备份/恢复任务在执行，请稍后再试');

            return CommandAlias::FAILURE;
        }

        try {
            return $this->runBackup($connection, $config, $driver);
        } finally {
            $this->mutex->release();
        }
    }

    private function runBackup(string $connection, array $config, string $driver): int
    {
        try {
            $toolchain = $this->toolchainChecker->inspect(requireMysql: false, requireMysqldump: true);
            if (! $toolchain['supported'] || $toolchain['mysqldump'] === null) {
                throw new RuntimeException(implode('；', $toolchain['errors']));
            }
            $handler = $this->backupService->makeHandler($driver);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            app(SystemAlert::class)->send(
                'backup',
                '备份客户端缺失',
                $e->getMessage(),
                [],
                self::DEDUPE_CLIENT_MISSING,
                24,
            );

            return CommandAlias::FAILURE;
        }

        $path = (string) ($this->option('path') ?: storage_path('databak'));
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            $this->error("创建目录失败: $path");

            return CommandAlias::FAILURE;
        }

        $timestamp = now()->format('Ymd_His');
        $database = (string) ($config['database'] ?? '');
        $finalPath = "$path/backup_$timestamp.sql.gz";
        $partPath = "$path/backup_$timestamp.".bin2hex(random_bytes(8)).'.sql.gz.part';
        $schemaPath = "$path/backup_$timestamp.schema.json";
        $schemaPartPath = $schemaPath.'.'.bin2hex(random_bytes(8)).'.part';
        $sqlPublished = false;
        if (file_exists($finalPath) || file_exists($schemaPath)) {
            $this->error('同名备份已存在，拒绝覆盖已完成产物');

            return CommandAlias::FAILURE;
        }

        $retainedTables = $this->backupService->resolveRetainedTables($database);
        $runtimeResetTables = $this->backupService->resolveRuntimeResetTables();
        $ignoreTables = array_values(array_unique(array_merge($retainedTables, $runtimeResetTables)));
        $this->info('忽略表: '.($ignoreTables === [] ? '无' : implode(', ', $ignoreTables)));

        try {
            $this->info('导出前数据库结构...');
            $structureBefore = $this->filteredStructure($connection, $retainedTables);

            $this->info('导出并压缩中...');
            $result = $handler->backup($config, $partPath, $ignoreTables, $toolchain);
            $this->syncFile($partPath);

            $this->info('导出后数据库结构...');
            $structureAfter = $this->filteredStructure($connection, $retainedTables);
            $diff = $this->structureService->compareBackupStructures($structureBefore, $structureAfter);
            if ($this->hasStructureDifferences($diff)) {
                throw new RuntimeException('备份期间数据库结构发生变化，已拒绝发布本次备份');
            }

            $schema = $this->buildSchema(
                $structureAfter,
                $toolchain,
                $result,
                $ignoreTables,
            );
            $this->writeSchemaPart($schemaPartPath, $schema);

            $this->info('原子发布备份...');
            if (! @rename($schemaPartPath, $schemaPath)) {
                throw new RuntimeException("无法原子发布 schema.json: $schemaPath");
            }
            $this->syncDirectory($path);
            if (! @rename($partPath, $finalPath)) {
                throw new RuntimeException("无法原子发布 SQL 备份: $finalPath");
            }
            $sqlPublished = true;
            $this->syncDirectory($path);
        } catch (Throwable $e) {
            if ($sqlPublished) {
                @unlink($finalPath);
            }
            @unlink($partPath);
            @unlink($schemaPartPath);
            if ($sqlPublished) {
                try {
                    $this->syncDirectory($path);
                } catch (Throwable) {
                    // 已失败路径仅尽力持久化撤下结果，保留原始异常。
                }
            }
            $this->error('备份失败: '.$e->getMessage());
            app(SystemAlert::class)->send(
                'backup',
                '数据库备份失败',
                $e->getMessage(),
                [],
                self::DEDUPE_DUMP_ERROR,
                24,
            );

            return CommandAlias::FAILURE;
        }

        $this->info('备份完成: '.$finalPath.' ('.$this->formatSize($result->outputBytes).')');
        $keepOption = $this->option('keep');
        $keep = $keepOption === null
            ? (int) config('database.backup.keep_days', 30)
            : (int) $keepOption;
        if ($keep > 0) {
            $minKeep = (int) config('database.backup.min_keep', 3);
            $purged = $this->purgeOldBackups($path, $keep, $minKeep);
            $this->info("清理 $purged 个过期备份（保留 $keep 天，兜底最少保留 $minKeep 份）");
        }

        $alert = app(SystemAlert::class);
        $alert->clearDedupe(self::DEDUPE_LOCK_CONTENTION);
        $alert->clearDedupe(self::DEDUPE_CLIENT_MISSING);
        $alert->clearDedupe(self::DEDUPE_DUMP_ERROR);

        return CommandAlias::SUCCESS;
    }

    private function filteredStructure(string $connection, array $ignoreTables): array
    {
        return $this->backupService->filterStructureTables(
            $this->structureService->exportBackupStructure($connection),
            $ignoreTables,
        );
    }

    private function buildSchema(
        array $structure,
        array $toolchain,
        PipelineResult $result,
        array $ignoreTables,
    ): array {
        $structure['generated_at'] = now()->toDateTimeString();
        $includedTables = array_values(array_diff(
            array_keys($structure['tables'] ?? []),
            $ignoreTables,
        ));
        $metadata = $this->metadataFactory->make(
            structure: $structure,
            toolchain: [
                'server_version' => $toolchain['server']['version'],
                'client_version' => $toolchain['mysqldump']['version'],
            ],
            streamStats: [
                'compressed_bytes' => $result->outputBytes,
                'uncompressed_bytes' => $result->inputBytes,
                'sha256' => $result->outputSha256,
            ],
            includedTables: $includedTables,
            excludedTables: $ignoreTables,
        );

        return array_merge($structure, $metadata);
    }

    private function writeSchemaPart(string $path, array $schema): void
    {
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException("无法创建 schema.json 临时文件: $path");
        }

        try {
            $written = fwrite($stream, $json);
            if ($written === false || $written !== strlen($json) || ! fflush($stream)) {
                throw new RuntimeException("无法写入 schema.json 临时文件: $path");
            }
            if (function_exists('fsync') && ! fsync($stream)) {
                throw new RuntimeException("无法同步 schema.json 临时文件: $path");
            }
        } finally {
            fclose($stream);
        }
    }

    private function syncFile(string $path): void
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("无法读取备份临时文件: $path");
        }

        try {
            if (function_exists('fsync') && ! fsync($stream)) {
                throw new RuntimeException("无法同步备份临时文件: $path");
            }
        } finally {
            fclose($stream);
        }
    }

    private function syncDirectory(string $directory): void
    {
        if (! function_exists('fsync')) {
            throw new RuntimeException('当前 PHP 不支持目录 fsync');
        }
        $stream = @fopen($directory, 'rb');
        if ($stream === false) {
            throw new RuntimeException("无法打开备份目录进行同步: $directory");
        }
        $synced = @fsync($stream);
        $closed = @fclose($stream);
        if (! $synced || ! $closed) {
            throw new RuntimeException("无法同步备份目录: $directory");
        }
    }

    private function hasStructureDifferences(array $diff): bool
    {
        foreach ($diff as $changes) {
            if ($changes !== []) {
                return true;
            }
        }

        return false;
    }

    private function purgeOldBackups(string $dir, int $keepDays, int $minKeep): int
    {
        $cutoff = time() - $keepDays * 86400;
        $files = glob("$dir/backup_*.sql.gz") ?: [];
        usort($files, fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

        $deleted = 0;
        foreach ($files as $index => $file) {
            if ($index < $minKeep || filemtime($file) >= $cutoff || ! @unlink($file)) {
                continue;
            }
            $deleted++;
            $schema = preg_replace('/\.sql\.gz$/', '.schema.json', $file);
            if ($schema !== null && is_file($schema)) {
                @unlink($schema);
            }
        }

        return $deleted;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2).' '.$units[$unit];
    }
}
