<?php

use App\Jobs\CreateBackupJob;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('互斥锁被占用时写 failed 进度并立即返回', function () {
    // 先占用 mutex
    $existing = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    $existing->get();

    $token = 'tok_'.uniqid();
    (new CreateBackupJob($token, adminId: 1))->handle(app(BackupService::class));

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toContain('已有备份/恢复任务在执行');

    $existing->release();
});

test('成功路径：Artisan::call 后写 completed 进度并附带输出', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('schedule:backup')
        ->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('备份完成: backup_20260424_120000.sql.gz');

    $token = 'tok_'.uniqid();
    (new CreateBackupJob($token, adminId: 7))->handle(app(BackupService::class));

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('completed')
        ->and($progress['message'])->toBe('备份完成')
        ->and($progress['admin_id'])->toBe(7)
        ->and($progress['output'])->toContain('backup_20260424_120000');
});

test('Artisan::call 返回非零退出码时写 failed 进度并保留 output（mysqldump 失败场景）', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('schedule:backup')
        ->andReturn(1);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn("忽略表: foo, bar\n导出并压缩中...\n备份失败: mysqldump 失败: unknown variable 'column-statistics=0'");

    $token = 'tok_'.uniqid();
    (new CreateBackupJob($token, adminId: 9))->handle(app(BackupService::class));

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toBe('备份失败')
        ->and($progress['output'])->toContain('mysqldump 失败')
        ->and($progress['output'])->toContain('column-statistics');

    // 锁应已释放
    $existing = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    expect($existing->get())->toBeTrue();
    $existing->release();
});

test('Artisan 抛异常时写 failed 进度并释放锁（后续可重试）', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('schedule:backup')
        ->andThrow(new RuntimeException('mysqldump 不可执行'));

    $token = 'tok_'.uniqid();
    (new CreateBackupJob($token, adminId: 1))->handle(app(BackupService::class));

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toContain('mysqldump 不可执行');

    // 锁应已释放：再起一个 Job 不会被「已有任务在执行」拦截
    $existing = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    expect($existing->get())->toBeTrue();
    $existing->release();
});
