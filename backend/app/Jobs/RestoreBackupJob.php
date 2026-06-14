<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Services\Backup\BackupService;
use App\Services\Backup\IncrementalSqlFilter;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 数据库恢复异步任务。
 *
 * 流程：
 * 1. 获取全局互斥锁
 * 2. 先拍一个 pre_restore 保险备份（不按天清理，按 pre_restore_keep 保留最近 N 份，防重试/多次恢复累积）
 * 3. artisan down 进入维护模式
 * 4. 按模式执行恢复：
 * - full — mysql 直接吞 .sql.gz（包含 DROP/CREATE/INSERT）
 * - incremental — IncrementalSqlFilter 剥 DROP/CREATE + INSERT IGNORE 后 mysql 执行
 * 5. artisan up 退出维护模式
 */
class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    // tries=5 吸收升级冻结期 SkipWhenUpgradeFrozen 的 release（每次 release 计入 attempts，
    // tries=1 会在第二次 pop 被 MaxAttemptsExceeded 杀在 handle 之前）。handle 自身 catch 全部
    // Throwable 写 failed 进度、不向 worker 抛，恢复失败本就不触发框架重试；maxExceptions=1 作
    // 防御性封顶。互斥锁 3600s 串行化，同一备份恢复终态幂等，freeze 重入安全。
    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(
        public string $token,
        public string $backupId,
        public string $mode,
        public int $adminId
    ) {}

    public function handle(BackupService $service, IncrementalSqlFilter $filter): void
    {
        // driver 守门：仅支持 MySQL/MariaDB
        $driver = (string) config('database.default');
        $driverName = (string) config("database.connections.$driver.driver", $driver);
        if (! in_array($driverName, ['mysql', 'mariadb'], true)) {
            $this->progress($service, 'failed', 'init', "当前数据库驱动 [$driverName] 暂不支持在线恢复，请手工恢复 $this->backupId");

            return;
        }

        // 二进制缺失时入口直接失败，避免拿锁后才发现
        try {
            $locator = app(BinaryLocator::class);
            $locator->mysqldump();
            $locator->mysql();
        } catch (BinaryNotFoundException $e) {
            $this->progress($service, 'failed', 'init', '未找到 '.$e->getTool().' 命令');

            return;
        }

        $lock = Cache::lock(BackupService::MUTEX_LOCK_KEY, 3600);

        if (! $lock->get()) {
            $this->progress($service, 'failed', 'init', '已有备份/恢复任务在执行，请稍后再试');

            return;
        }

        $backup = $service->resolveBackup($this->backupId);
        if ($backup === null) {
            $this->progress($service, 'failed', 'init', "备份不存在: $this->backupId");
            $lock->release();

            return;
        }

        $downEntered = false;
        $tmpSql = null;

        try {
            // 1. 拍保险备份
            $this->progress($service, 'running', 'snapshot', '正在创建恢复前快照...');
            $snapshotExit = Artisan::call('schedule:backup', ['--prefix' => 'pre_restore', '--keep' => 0]);
            if ($snapshotExit !== 0) {
                throw new RuntimeException('恢复前快照失败: '.trim(Artisan::output()));
            }

            // 2. 维护模式
            $this->progress($service, 'running', 'maintenance', '进入维护模式...');
            Artisan::call('down', ['--retry' => 60]);
            $downEntered = true;

            // 3. 执行恢复
            if ($this->mode === 'incremental') {
                $this->progress($service, 'running', 'filtering', '生成增量 SQL...');
                $tmpSql = tempnam(sys_get_temp_dir(), 'restore_').'.sql';
                $stats = $filter->filter($backup['sql'], $tmpSql);
                $this->progress($service, 'running', 'restoring',
                    "执行增量恢复（{$stats['rewritten_insert']} 条 INSERT IGNORE）...");
                $this->runMysqlFromFile($tmpSql);
            } else {
                $this->progress($service, 'running', 'restoring', '执行全量恢复（覆盖当前库）...');
                $this->runMysqlFromGzip($backup['sql']);
            }

            // 4. 退出维护
            Artisan::call('up');
            $downEntered = false;

            $this->progress($service, 'completed', 'done', '恢复完成（已自动创建恢复前快照）');
        } catch (Throwable $e) {
            Log::error('RestoreBackupJob failed', ['error' => $e->getMessage()]);
            $this->progress($service, 'failed', 'error', '恢复失败: '.$e->getMessage());
        } finally {
            if ($downEntered) {
                try {
                    Artisan::call('up');
                } catch (Throwable) {
                    // ignore
                }
            }
            if ($tmpSql !== null && is_file($tmpSql)) {
                @unlink($tmpSql);
            }
            $lock->release();
        }
    }

    private function progress(BackupService $service, string $status, string $stage, string $message): void
    {
        $service->setJobProgress($this->token, [
            'status' => $status,
            'stage' => $stage,
            'message' => $message,
            'backup_id' => $this->backupId,
            'mode' => $this->mode,
            'admin_id' => $this->adminId,
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * 把 gzip 的 SQL 喂给 mysql（全量恢复路径）。
     */
    private function runMysqlFromGzip(string $gzPath): void
    {
        $cnfPath = $this->writeCnfFile();
        try {
            $gz = gzopen($gzPath, 'rb');
            if ($gz === false) {
                throw new RuntimeException("无法打开 gzip: $gzPath");
            }

            $process = $this->newMysqlProcess($cnfPath);
            $process->setInput($this->gzipReadStream($gz));
            $process->setTimeout(3600);
            $process->run();

            gzclose($gz);

            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysql 全量恢复失败: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }
        } finally {
            @unlink($cnfPath);
        }
    }

    /**
     * 把普通 .sql 文件喂给 mysql（增量恢复路径）。
     */
    private function runMysqlFromFile(string $sqlPath): void
    {
        $cnfPath = $this->writeCnfFile();
        try {
            $fh = fopen($sqlPath, 'rb');
            if ($fh === false) {
                throw new RuntimeException("无法打开 sql: $sqlPath");
            }

            $process = $this->newMysqlProcess($cnfPath);
            $process->setInput($fh);
            $process->setTimeout(3600);
            $process->run();

            fclose($fh);

            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysql 增量恢复失败: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }
        } finally {
            @unlink($cnfPath);
        }
    }

    private function newMysqlProcess(string $cnfPath): Process
    {
        $bin = app(BinaryLocator::class)->mysql();
        $database = config('database.connections.'.config('database.default').'.database');

        return new Process([
            $bin,
            "--defaults-extra-file=$cnfPath",
            '--default-character-set=utf8mb4',
            $database,
        ]);
    }

    /**
     * Symfony Process 的 setInput 支持 iterable/callable；gzread 逐块产出。
     */
    private function gzipReadStream($gz): iterable
    {
        while (! gzeof($gz)) {
            $chunk = gzread($gz, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            yield $chunk;
        }
    }

    private function writeCnfFile(): string
    {
        $cfg = config('database.connections.'.config('database.default'));
        $path = tempnam(sys_get_temp_dir(), 'mysql_');
        if ($path === false) {
            throw new RuntimeException('无法创建临时配置文件');
        }

        $escape = fn (string $v) => str_replace(['\\', '"'], ['\\\\', '\\"'], $v);

        $content = "[client]\n"
        .'host='.($cfg['host'] ?? '127.0.0.1')."\n"
        .'port='.($cfg['port'] ?? '3306')."\n"
        .'user='.($cfg['username'] ?? '')."\n"
        .'password="'.$escape((string) ($cfg['password'] ?? '')).'"'."\n"
        .'default-character-set='.($cfg['charset'] ?? 'utf8mb4')."\n";

        file_put_contents($path, $content);
        chmod($path, 0600);

        return $path;
    }
}
