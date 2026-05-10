<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'schedule:backup
 {--keep= : 保留天数，0 表示不清理；默认读 config("database.backup.keep_days")；仅清理 backup_ 前缀文件，pre_restore_ 永不自动清理}
 {--path= : 输出目录，默认 storage/databak}
 {--prefix=backup : 文件名前缀，内部调用可传 pre_restore}';

    protected $description = '备份数据库（mysql）：通过 MysqlBackupHandler 走 mysqldump，剔除日志与队列等运行时表';

    public function __construct(
        private DatabaseStructureService $structureService,
        private BackupService $backupService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.$connection");
        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error("不支持的数据库驱动: {$driver}（仅支持 mysql）");

            return CommandAlias::FAILURE;
        }

        try {
            $handler = $this->backupService->makeHandler($driver);
            $handler->ensureClient();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            foreach (BackupService::installHintLines($driver) as $line) {
                $this->line($line);
            }

            return CommandAlias::FAILURE;
        }

        $path = $this->option('path') ?: storage_path('databak');

        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            $this->error("创建目录失败: $path");

            return CommandAlias::FAILURE;
        }

        $prefix = (string) $this->option('prefix') ?: 'backup';
        if (! preg_match('/^[a-z_]+$/', $prefix)) {
            $this->error('--prefix 只能包含小写字母与下划线');

            return CommandAlias::FAILURE;
        }

        $timestamp = now()->format('Ymd_His');
        $database = (string) ($config['database'] ?? '');
        $finalPath = "$path/{$prefix}_$timestamp.sql.gz";
        $schemaPath = "$path/{$prefix}_$timestamp.schema.json";

        $ignoreTables = $this->backupService->resolveIgnoreTables($database);
        $this->info('忽略表: '.(empty($ignoreTables) ? '无' : implode(', ', $ignoreTables)));

        try {
            $this->info('导出并压缩中...');
            $handler->backup($config, $finalPath, $ignoreTables);

            $this->info('导出数据库结构到 schema.json...');
            $this->writeSchemaJson($connection, $schemaPath, $ignoreTables);
        } catch (Throwable $e) {
            @unlink($finalPath);
            @unlink($schemaPath);
            $this->error('备份失败: '.$e->getMessage());

            return CommandAlias::FAILURE;
        }

        $size = is_file($finalPath) ? filesize($finalPath) : 0;
        $this->info('备份完成: '.$finalPath.' ('.$this->formatSize((int) $size).')');

        $keepOption = $this->option('keep');
        $keep = $keepOption === null
        ? (int) config('database.backup.keep_days', 30)
        : (int) $keepOption;
        if ($keep > 0) {
            $minKeep = (int) config('database.backup.min_keep', 3);
            $purged = $this->purgeOldBackups($path, $keep, $minKeep);
            $this->info("清理 $purged 个过期备份（保留 $keep 天，兜底最少保留 $minKeep 份）");
        }

        return CommandAlias::SUCCESS;
    }

    /**
     * 导出当前数据库结构为 JSON，剔除与 dump 同样被忽略的表，确保 schema 与备份内容一致。
     */
    private function writeSchemaJson(string $connection, string $outputPath, array $ignoreTables): void
    {
        $structure = $this->structureService->exportCurrentStructure($connection);
        $structure = $this->backupService->filterStructureTables($structure, $ignoreTables);
        $structure['generated_at'] = now()->toDateTimeString();

        $json = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('schema.json 序列化失败');
        }

        if (file_put_contents($outputPath, $json) === false) {
            throw new RuntimeException("无法写入 schema.json: $outputPath");
        }
    }

    /**
     * 清理过期的 backup_ 前缀备份（pre_restore_ 前缀永不自动清理）。
     *
     * 策略：按 mtime 倒序排序，前 $minKeep 份无论多老都保留；其余按 $keepDays 判断。
     * 这样既能"保留 30 天内"，又能防止长期不创建被清到 0 份。
     */
    private function purgeOldBackups(string $dir, int $keepDays, int $minKeep): int
    {
        $cutoff = time() - $keepDays * 86400;
        $files = glob("$dir/backup_*.sql.gz") ?: [];

        // 按 mtime 降序（新在前）
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $deleted = 0;
        foreach ($files as $idx => $file) {
            // 前 minKeep 份无条件保留
            if ($idx < $minKeep) {
                continue;
            }
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
                $schema = preg_replace('/\.sql\.gz$/', '.schema.json', $file);
                if ($schema && is_file($schema)) {
                    @unlink($schema);
                }
            }
        }

        return $deleted;
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2).' '.$units[$i];
    }
}
