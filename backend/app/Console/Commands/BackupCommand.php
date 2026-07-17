<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Notification\SystemAlert;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'schedule:backup
 {--keep= : 保留天数，0 表示不清理；默认读 config("database.backup.keep_days")；仅按天清理 backup_ 前缀，pre_restore_ 不参与（改按 config("database.backup.pre_restore_keep") 数量上限清理）}
 {--path= : 输出目录，默认 storage/databak}
 {--prefix=backup : 文件名前缀，内部调用可传 pre_restore}
 {--internal-no-lock : （内部）调用方已持 backup:mutex，仅供 CreateBackupJob/RestoreBackupJob 重入旁路，勿手工使用}';

    protected $description = '备份数据库（mysql）：通过 MysqlBackupHandler 走 mysqldump，剔除日志与队列等运行时表';

    // SystemAlert 去重键：send 与 clear 两端引同一常量，杜绝裸键名两处手写、打错一字致 healthy 分支
    // 清错键 → 去重永不解除（计数型 forever vs 24h TTL 的分叉是有意设计，见 notification.md，不在此统一）。
    private const DEDUPE_LOCK_CONTENTION = 'backup_lock_contention';

    private const DEDUPE_CLIENT_MISSING = 'backup_client_missing';

    private const DEDUPE_DUMP_ERROR = 'backup_dump_error';

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

        // 定时备份是「可跳过的从操作」：非阻塞抢 backup:mutex，避免与持锁 3600s 的
        // Create/RestoreBackupJob 并发 dump 出半恢复库（垃圾备份污染灾备轮转）。
        // --internal-no-lock 供已持锁的父 Job 重入旁路（防命令无脑抢锁 → 自死锁 → pre_restore 快照缺失）。
        $owns = ! $this->option('internal-no-lock');
        $lock = null;
        if ($owns) {
            $lock = Cache::lock(BackupService::MUTEX_LOCK_KEY, 3600);
            if (! $lock->get()) {
                app(SystemAlert::class)->send(
                    'backup',
                    '定时备份跳过（互斥）',
                    '已有备份/恢复任务执行中，本次定时备份已跳过',
                    [],
                    self::DEDUPE_LOCK_CONTENTION,
                    24,
                );

                // 跳过≠失败：返回 SUCCESS，避免与 console 层 onFailure 双告警
                return CommandAlias::SUCCESS;
            }
        }

        try {
            return $this->runBackup($connection, $config, $driver, $owns);
        } finally {
            $lock?->release();
        }
    }

    /**
     * 实际执行备份（锁已由 handle 处理）。仅 $owns（自持锁的定时/手工入口）才发/清 SystemAlert；
     * --internal-no-lock 的父 Job 重入路径不告警（父 Job 自管进度上报）。
     */
    private function runBackup(string $connection, array $config, string $driver, bool $owns): int
    {
        try {
            $handler = $this->backupService->makeHandler($driver);
            $handler->ensureClient();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            foreach (BackupService::installHintLines($driver) as $line) {
                $this->line($line);
            }
            if ($owns) {
                app(SystemAlert::class)->send(
                    'backup',
                    '备份客户端缺失',
                    $e->getMessage(),
                    [],
                    self::DEDUPE_CLIENT_MISSING,
                    24,
                );
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
            if ($owns) {
                app(SystemAlert::class)->send(
                    'backup',
                    '数据库备份失败',
                    $e->getMessage(),
                    [],
                    self::DEDUPE_DUMP_ERROR,
                    24,
                );
            }

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

        // pre_restore_ 不参与上面的天数清理（恢复前保险快照，随时可能要回退），
        // 改用数量上限兜底：防止恢复重试（tries>1）或多次恢复导致其无限累积占盘。
        // keep<1（不限制）的语义由 purgePreRestoreSnapshots 自身处理（no-op），此处无条件调用。
        if ($prefix === 'pre_restore') {
            $preKeep = (int) config('database.backup.pre_restore_keep', 5);
            $purged = $this->purgePreRestoreSnapshots($path, $preKeep);
            if ($preKeep > 0) {
                $this->info("清理 $purged 个旧的 pre_restore 快照（保留最近 $preKeep 份）");
            }
        }

        // 成功即清去重键（对齐 E 系恢复语义：故障恢复后下次异常立即再告警）
        if ($owns) {
            $alert = app(SystemAlert::class);
            $alert->clearDedupe(self::DEDUPE_LOCK_CONTENTION);
            $alert->clearDedupe(self::DEDUPE_CLIENT_MISSING);
            $alert->clearDedupe(self::DEDUPE_DUMP_ERROR);
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
     * 清理过期的 backup_ 前缀备份（pre_restore_ 不参与天数清理，由 purgePreRestoreSnapshots 按数量上限清理）。
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

    /**
     * 按数量上限清理 pre_restore_ 快照：按 mtime 倒序保留最近 $keep 份，其余成对删除（含 schema.json）。
     *
     * 与 purgeOldBackups（backup_ 前缀、按天清理 + min_keep 兜底）互补 —— pre_restore_ 是恢复前
     * 保险快照，不按天清理，但需防恢复重试/多次恢复无限累积占盘，故用数量上限封顶。
     */
    private function purgePreRestoreSnapshots(string $dir, int $keep): int
    {
        // keep<1：不限制（永久保留），no-op —— 与 config 注释 "0 表示不限制" 契约自洽，
        // 且防调用方误传 0/负数当"无限"却把快照全删的 footgun（$idx < keep 恒假会删光）。
        if ($keep < 1) {
            return 0;
        }

        $files = glob("$dir/pre_restore_*.sql.gz") ?: [];

        // 按 mtime 降序（新在前）
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $deleted = 0;
        foreach ($files as $idx => $file) {
            // 前 keep 份（最新）无条件保留
            if ($idx < $keep) {
                continue;
            }
            if (@unlink($file)) {
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
