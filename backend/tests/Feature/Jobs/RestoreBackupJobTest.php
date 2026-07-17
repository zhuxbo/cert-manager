<?php

use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupService;
use App\Services\Backup\IncrementalSqlFilter;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();

    // RestoreBackupJob 仅支持 mysql/mariadb；其它 driver 由 Unit/Jobs/RestoreBackupJobDriverGuardTest 覆盖
    // 这里默认 driver 不变，依赖 .env / phpunit.xml 默认值；非 mysql/mariadb 全量测试应显式跳过。
    $driver = (string) config('database.default');
    $driverName = (string) config("database.connections.$driver.driver", $driver);
    if (! in_array($driverName, ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped('RestoreBackupJob 在线恢复路径仅 mysql/mariadb 适用，非 mysql/mariadb 守门由 RestoreBackupJobDriverGuardTest 覆盖');
    }

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
    // delegate 后通过 mock BinaryLocator 模拟"找不到 mysqldump"（Job 先调 mysqldump）
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andThrow(new BinaryNotFoundException(tool: 'mysqldump', triedPaths: ['/nonexistent']));
    $this->app->instance(BinaryLocator::class, $mock);

    $token = 'tok_'.uniqid();
    (new RestoreBackupJob($token, 'backup_20260424_120000', 'full', adminId: 1))
        ->handle(app(BackupService::class), new IncrementalSqlFilter);

    $progress = app(BackupService::class)->getJobProgress($token);
    expect($progress['status'])->toBe('failed')
        ->and($progress['message'])->toContain('未找到 mysqldump 命令');

    // 锁未被持有
    $lock = Cache::lock(BackupService::MUTEX_LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('互斥锁被占用时写 failed 进度', function () {
    // 让二进制探测通过：mock BinaryLocator 返回 fake mysql 客户端脚本路径
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andReturn(fakeMysqlClientBin('mysqldump'));
    $mock->shouldReceive('mysql')->andReturn(fakeMysqlClientBin('mysql'));
    $this->app->instance(BinaryLocator::class, $mock);

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
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andReturn(fakeMysqlClientBin('mysqldump'));
    $mock->shouldReceive('mysql')->andReturn(fakeMysqlClientBin('mysql'));
    $this->app->instance(BinaryLocator::class, $mock);

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

test('H3 pre_restore 快照调用点带 --internal-no-lock（持锁父任务重入不自死锁）', function () {
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andReturn(fakeMysqlClientBin('mysqldump'));
    $mock->shouldReceive('mysql')->andReturn(fakeMysqlClientBin('mysql'));
    $this->app->instance(BinaryLocator::class, $mock);

    // 造一个可解析的备份，让流程走到 snapshot 步骤
    $backupId = 'backup_20260424_120000';
    file_put_contents($this->testDir.'/'.$backupId.'.sql.gz', 'fake');

    // 捕获 snapshot 的 schedule:backup 参数，捕获后抛异常停在快照后（避免真实 restore）
    $captured = null;
    Artisan::shouldReceive('call')->andReturnUsing(function ($cmd, $params = []) use (&$captured) {
        if ($cmd === 'schedule:backup') {
            $captured = $params;
            throw new RuntimeException('stop after snapshot capture');
        }

        return 0;
    });

    $token = 'tok_'.uniqid();
    (new RestoreBackupJob($token, $backupId, 'full', adminId: 1))
        ->handle(app(BackupService::class), new IncrementalSqlFilter);

    expect($captured)->toBe([
        '--prefix' => 'pre_restore',
        '--keep' => 0,
        '--internal-no-lock' => true,
    ]);
});
