<?php

use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupService;
use App\Services\Backup\IncrementalSqlFilter;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

/**
 * RestoreBackupJob driver 守门：当前仅支持 mysql / mariadb；其余 driver 必须立即 failed，不拿锁也不调 mysqldump。
 *
 * 单元测试层只验证守门分支：
 * - 直接构造 BackupService 子类（不走 DB），不依赖 RefreshDatabase
 * - 显式改 config('database.default') 模拟非 mysql 环境
 * - 验证 progress = failed + message 含驱动名 + 锁未被持有
 */
beforeEach(function () {
    Cache::flush();

    $this->testDir = storage_path('databak_driver_guard_'.uniqid());
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

test('未知 driver 直接 failed，不拿锁也不调 BinaryLocator', function () {
    // 注：Unit 测试不走 RefreshDatabase，config('database.default') 修改不会触发实际连接重建
    config(['database.default' => 'unknown']);
    config(['database.connections.unknown' => ['driver' => 'unknown']]);

    $token = 'tok_'.uniqid();
    (new RestoreBackupJob($token, 'backup_20260424_120000', 'full', adminId: 1))
        ->handle(app(BackupService::class), new IncrementalSqlFilter);

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toContain('暂不支持在线恢复')
        ->and($progress['message'])->toContain('unknown');

    // 守门发生在拿锁之前 → 锁应当可被立即拿到
    $lock = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('mariadb driver 不被守门拦截（保留路径，进入后续 BinaryLocator 探测）', function () {
    config(['database.default' => 'mariadb']);
    config(['database.connections.mariadb' => ['driver' => 'mariadb']]);
    // mock BinaryLocator->mysqldump() 抛 BinaryNotFoundException，让 Job 在二进制探测处失败；
    // 关键：错误信息应含 "未找到 mysqldump 命令"（说明已通过守门），而非 "暂不支持"
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andThrow(new BinaryNotFoundException(tool: 'mysqldump', triedPaths: ['/nonexistent']));
    $this->app->instance(BinaryLocator::class, $mock);

    $token = 'tok_'.uniqid();
    (new RestoreBackupJob($token, 'backup_20260424_120000', 'full', adminId: 1))
        ->handle(app(BackupService::class), new IncrementalSqlFilter);

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->not->toContain('暂不支持在线恢复');
});
