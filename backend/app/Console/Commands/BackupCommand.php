<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Process\Process;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'schedule:backup
        {--keep= : 保留天数，0 表示不清理；默认读 config("database.backup.keep_days")；仅清理 backup_ 前缀文件，pre_restore_ 永不自动清理}
        {--path= : 输出目录，默认 storage/databak}
        {--prefix=backup : 文件名前缀，内部调用可传 pre_restore}';

    protected $description = '备份数据库：mysqldump 核心数据，剔除日志与队列等运行时表';

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

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->error('仅支持 mysql 驱动');

            return CommandAlias::FAILURE;
        }

        try {
            $dumpBin = $this->backupService->ensureMysqlClient('mysqldump');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            foreach (BackupService::installHintLines() as $line) {
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
        $database = $config['database'];
        $finalPath = "$path/{$prefix}_$timestamp.sql.gz";
        $schemaPath = "$path/{$prefix}_$timestamp.schema.json";

        $ignoreTables = $this->backupService->resolveIgnoreTables($database);
        $this->info('忽略表: '.(empty($ignoreTables) ? '无' : implode(', ', $ignoreTables)));

        $cnfPath = $this->writeCnfFile($config);

        try {
            $this->info('导出并压缩中...');
            $this->runDumpGzip($dumpBin, $cnfPath, $database, $ignoreTables, $finalPath);

            $this->info('导出数据库结构到 schema.json...');
            $this->writeSchemaJson($connection, $schemaPath, $ignoreTables);
        } catch (Throwable $e) {
            @unlink($finalPath);
            @unlink($schemaPath);
            $this->error('备份失败: '.$e->getMessage());

            return CommandAlias::FAILURE;
        } finally {
            @unlink($cnfPath);
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
     * 写临时凭据文件，避免密码出现在 ps 输出里。
     */
    private function writeCnfFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mysqldump_');
        if ($path === false) {
            throw new RuntimeException('无法创建临时配置文件');
        }

        $escape = fn (string $v) => str_replace(['\\', '"'], ['\\\\', '\\"'], $v);

        $content = "[client]\n"
            .'host='.($config['host'] ?? '127.0.0.1')."\n"
            .'port='.($config['port'] ?? '3306')."\n"
            .'user='.($config['username'] ?? '')."\n"
            .'password="'.$escape((string) ($config['password'] ?? '')).'"'."\n"
            .'default-character-set='.($config['charset'] ?? 'utf8mb4')."\n";

        file_put_contents($path, $content);
        chmod($path, 0600);

        return $path;
    }

    /**
     * mysqldump 流式写入 gzip 文件，一次完成。
     */
    private function runDumpGzip(
        string $bin,
        string $cnfPath,
        string $database,
        array $ignoreTables,
        string $outputPath
    ): void {
        $args = [
            $bin,
            "--defaults-extra-file=$cnfPath",
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
            '--column-statistics=0',
            '--default-character-set=utf8mb4',
            '--hex-blob',
            '--add-drop-table',
        ];

        foreach ($ignoreTables as $t) {
            $args[] = "--ignore-table=$database.$t";
        }

        $args[] = $database;

        $gz = gzopen($outputPath, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("无法创建 gzip 文件: $outputPath");
        }

        $process = new Process($args);
        $process->setTimeout(3600);

        try {
            $process->run(function ($type, $buffer) use ($gz) {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                }
            });
        } finally {
            gzclose($gz);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump 失败: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * 导出当前数据库结构为 JSON，剔除与 mysqldump 同样被忽略的表，确保 schema 与备份内容一致。
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
                // 同步删除对应的 schema.json
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
