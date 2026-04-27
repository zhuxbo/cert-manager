<?php

use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupService;
use App\Services\Backup\IncrementalSqlFilter;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();

    // 独立目录避免污染
    $this->testDir = storage_path('databak_job_test_'.uniqid());
    mkdir($this->testDir, 0755, true);

    $this->service = new class($this->testDir) extends BackupService
    {
        public function __construct(private string $dir) {}

        public function basePath(): string
        {
            return $this->dir;
        }
    };
    $this->app->instance(BackupService::class, $this->service);
});

afterEach(function () {
    if (isset($this->testDir) && is_dir($this->testDir)) {
        array_map('unlink', glob($this->testDir.'/*') ?: []);
        rmdir($this->testDir);
    }
});

test('mysqldump/mysql 不可用时入口直接 failed，不拿锁', function () {
    config(['database.backup.mysqldump_bin' => '/nonexistent/mysqldump']);

    $token = 'tok_'.uniqid();
    (new RestoreBackupJob($token, 'backup_20260424_120000', 'full', adminId: 1))
        ->handle(app(BackupService::class), new IncrementalSqlFilter);

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toContain('mysqldump 不可执行');

    // 锁未被持有
    $lock = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('互斥锁被占用时写 failed 进度', function () {
    // 让 ensureMysqlClient 通过：fake mysql 客户端脚本（输出符合 --version 特征）
    config(['database.backup.mysqldump_bin' => fakeMysqlClientBin('mysqldump')]);
    config(['database.backup.mysql_bin' => fakeMysqlClientBin('mysql')]);

    $existing = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    $existing->get();

    $token = 'tok_'.uniqid();
    (new RestoreBackupJob($token, 'backup_20260424_120000', 'incremental', adminId: 1))
        ->handle(app(BackupService::class), new IncrementalSqlFilter);

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toContain('已有备份/恢复任务在执行');

    $existing->release();
});

test('备份不存在时写 failed 进度并释放锁', function () {
    config(['database.backup.mysqldump_bin' => fakeMysqlClientBin('mysqldump')]);
    config(['database.backup.mysql_bin' => fakeMysqlClientBin('mysql')]);

    $token = 'tok_'.uniqid();
    (new RestoreBackupJob($token, 'backup_19990101_000000', 'full', adminId: 1))
        ->handle(app(BackupService::class), new IncrementalSqlFilter);

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toContain('备份不存在');

    // 锁应已释放
    $lock = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
