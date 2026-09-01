<?php

use App\Console\Commands\BackupCommand;
use App\Services\Backup\BackupHandlerInterface;
use App\Services\Backup\BackupMetadataFactory;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseOperationMutex;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\PipelineResult;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Notification\SystemAlert;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/../../Support/BackupCommandFsyncHook.php';

/**
 * 构造一个 backup_ 文件及 schema.json，mtime 指定为 N 天前。
 */
function makeBackup(string $dir, string $stamp, int $daysAgo): array
{
    $sql = $dir.'/backup_'.$stamp.'.sql.gz';
    $schema = $dir.'/backup_'.$stamp.'.schema.json';
    file_put_contents($sql, 'fake');
    file_put_contents($schema, '{"tables":[]}');
    $time = time() - $daysAgo * 86400;
    touch($sql, $time);
    touch($schema, $time);

    return ['sql' => $sql, 'schema' => $schema];
}

/**
 * 用 fake handler 替换 BackupService::makeHandler，避免执行真实 mysqldump。
 * 探测、backup 写一个 fake gz 文件即返回 —— 用于让命令在测试环境跑通到真正想验证的
 * 路径（清理逻辑、prefix 校验等），不依赖本地 mysqldump 是否在标准 PATH 上。
 *
 * 之前依赖 Symfony ExecutableFinder + 开发者 shell PATH 隐式找到 mysqldump 才能跑，
 * 是"开发机能跑、生产挂"的典型隐患。
 *
 * 用 makePartial：保留 BackupService 其他真实方法，只覆盖命令依赖的外部边界。
 */
function fakeOkBackupService(): void
{
    bindSupportedBackupToolchain();
    $handler = Mockery::mock(BackupHandlerInterface::class);
    $handler->shouldReceive('backup')->andReturnUsing(function ($cfg, $out) {
        $gz = gzopen($out, 'wb');
        gzwrite($gz, "-- fake\n");
        gzclose($gz);

        return new PipelineResult(
            inputBytes: 8,
            outputBytes: (int) filesize($out),
            outputSha256: (string) hash_file('sha256', $out),
            exitCodes: ['dump' => 0, 'gzip' => 0],
            stderr: ['dump' => '', 'gzip' => ''],
        );
    });

    $service = Mockery::mock(BackupService::class)->makePartial();
    $service->shouldReceive('makeHandler')->andReturn($handler);
    $service->shouldReceive('resolveRetainedTables')->andReturn([]);
    $service->shouldReceive('resolveRuntimeResetTables')->andReturn([]);
    app()->instance(BackupService::class, $service);
}

function bindBackupDatabaseMutex(bool $acquired = true): void
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDatabaseName')->andReturn('ssl_manager_test');
    $connection->shouldReceive('selectOne')->andReturnUsing(function (string $sql) use ($acquired): object {
        if (str_contains($sql, 'GET_LOCK')) {
            return (object) ['acquired' => $acquired ? 1 : 0];
        }

        return (object) ['released' => 1];
    });
    $manager = Mockery::mock(DatabaseManager::class);
    $manager->shouldReceive('connection')->andReturn($connection);
    app()->instance(DatabaseOperationMutex::class, new DatabaseOperationMutex($manager));
}

function bindSupportedBackupToolchain(): void
{
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('mysqldump')->andReturn(fakeMysqlClientBin('mysqldump'));
    $locator->shouldReceive('gzip')->andReturn(fakeMysqlClientBin('gzip'));
    app()->instance(BinaryLocator::class, $locator);
    app()->forgetInstance(MysqlToolchainChecker::class);
}

function bindAtomicBackupService(
    string $directory,
    ?callable $afterPartWritten = null,
    array $retainedTables = [],
    array $runtimeResetTables = ['jobs'],
): BackupService {
    $handler = Mockery::mock(BackupHandlerInterface::class);
    $handler->shouldReceive('backup')->andReturnUsing(
        function (array $config, string $partPath, array $ignoreTables) use ($afterPartWritten): PipelineResult {
            $gz = gzopen($partPath, 'wb1');
            gzwrite($gz, "CREATE TABLE atomic_test (id int);\n");
            gzclose($gz);
            $afterPartWritten?->__invoke($partPath, $ignoreTables);

            return new PipelineResult(
                inputBytes: 35,
                outputBytes: (int) filesize($partPath),
                outputSha256: (string) hash_file('sha256', $partPath),
                exitCodes: ['dump' => 0, 'gzip' => 0],
                stderr: ['dump' => '', 'gzip' => ''],
            );
        },
    );

    $service = new class($directory, $handler, $retainedTables, $runtimeResetTables) extends BackupService
    {
        public function __construct(
            private readonly string $directory,
            private readonly BackupHandlerInterface $handler,
            private readonly array $retainedTables,
            private readonly array $runtimeResetTables,
        ) {}

        public function basePath(): string
        {
            return $this->directory;
        }

        public function makeHandler(?string $driver = null): BackupHandlerInterface
        {
            return $this->handler;
        }

        public function resolveRetainedTables(string $database): array
        {
            return $this->retainedTables;
        }

        public function resolveRuntimeResetTables(): array
        {
            return $this->runtimeResetTables;
        }
    };
    app()->instance(BackupService::class, $service);

    return $service;
}

function atomicBackupStructure(string $type = 'int'): array
{
    return [
        'tables' => [
            'atomic_test' => [
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '',
                'auto_increment' => 2,
                'data_length' => 16384,
                'index_length' => 0,
                'columns' => [
                    'id' => [
                        'position' => 1,
                        'type' => $type,
                        'nullable' => false,
                        'default' => null,
                        'extra' => '',
                        'comment' => '',
                        'character_set' => null,
                        'generation_expression' => '',
                    ],
                ],
                'indexes' => [],
                'foreign_keys' => [],
            ],
        ],
    ];
}

function bindEmptyBackupStructure(int $times = 2): void
{
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportBackupStructure')->times($times)->andReturn(['tables' => []]);
    app()->instance(DatabaseStructureService::class, $structure);
}

beforeEach(function () {
    unset($GLOBALS['backup_command_fsync_hook']);
    $this->testDir = storage_path('databak_unit_'.uniqid());
    mkdir($this->testDir, 0755, true);
});

afterEach(function () {
    unset($GLOBALS['backup_command_fsync_hook']);
    if (isset($this->testDir) && is_dir($this->testDir)) {
        foreach (glob($this->testDir.'/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        rmdir($this->testDir);
    }
});

test('keep_days 清理过期备份，同步删 schema.json', function () {
    // schedule:backup 必须实际备份成功后才会执行 purge。

    $old = makeBackup($this->testDir, '20260101_000000', 60);
    $fresh = makeBackup($this->testDir, '20260420_000000', 3);

    config(['database.backup.min_keep' => 0]);

    fakeOkBackupService();
    bindEmptyBackupStructure();

    Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--keep' => 30,
    ]);

    expect(is_file($old['sql']))->toBeFalse()
        ->and(is_file($old['schema']))->toBeFalse()
        ->and(is_file($fresh['sql']))->toBeTrue();
});

test('min_keep 兜底：即使全部过期也至少保留 N 份最新的', function () {
    // 同 keep_days：依赖 schedule:backup 实际产生新备份才会跑 purge。

    // mtime 错开：最旧 62 天前，次旧 61 天前，最新 60 天前（仍全部过期）
    $oldest = makeBackup($this->testDir, '20260101_000001', 62);
    $middle = makeBackup($this->testDir, '20260101_000002', 61);
    $latest = makeBackup($this->testDir, '20260101_000003', 60);

    config(['database.backup.min_keep' => 2]);

    fakeOkBackupService();
    bindEmptyBackupStructure();

    Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--keep' => 30,
    ]);

    // 命令会新建一份 + min_keep=2 保留"最近 2 份"（新建 + $latest），
    // $middle 和 $oldest 应被清
    expect(is_file($latest['sql']))->toBeTrue()
        ->and(is_file($middle['sql']))->toBeFalse()
        ->and(is_file($oldest['sql']))->toBeFalse();
});

test('backup_ 天数清理不主动删除历史 pre_restore 文件', function () {
    $preRestore = makeBackup($this->testDir, '20260101_000001', 365); // 1 年前
    // pre_restore_ 不是 makeBackup 造的，手动搞一个
    $sqlPre = $this->testDir.'/pre_restore_20260101_000001.sql.gz';
    rename($preRestore['sql'], $sqlPre);
    touch($sqlPre, time() - 365 * 86400);

    config(['database.backup.min_keep' => 0]);

    fakeOkBackupService();
    bindEmptyBackupStructure();

    Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--keep' => 30,
    ]);

    expect(is_file($sqlPre))->toBeTrue();
});

test('未支持的驱动时立即中止', function () {
    // 用 BackupService::makeHandler 直接覆盖：注入一个不识别的 driver
    // 不动 database.default 避免 RefreshDatabase tearDown 时撞上未支持的连接
    $originalDefault = config('database.default');

    // 临时建一个连接但只在 BackupCommand 的 handle 中读 driver — 切 default 一闪即弃
    config(['database.connections.unknown_driver_conn' => [
        'driver' => 'mongodb',
        'database' => 'whatever',
    ]]);
    config(['database.default' => 'unknown_driver_conn']);

    try {
        $exit = Artisan::call('schedule:backup', [
            '--path' => $this->testDir,
        ]);

        $output = Artisan::output();
        expect($exit)->not->toBe(0)
            ->and($output)->toContain('不支持的数据库驱动');
    } finally {
        // 立即恢复，避免 RefreshDatabase 的 afterEach 在 unknown driver 上炸
        config(['database.default' => $originalDefault]);
    }
});

test('mysqldump 不可用（BinaryLocator 抛 BinaryNotFoundException）时立即中止', function () {
    if (config('database.connections.'.config('database.default').'.driver') !== 'mysql') {
        test()->markTestSkipped('mysql-only');
    }

    // delegate 后通过 mock BinaryLocator 模拟"找不到 mysqldump"（不再依赖 config 路径）
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('mysqldump')->andThrow(new BinaryNotFoundException(tool: 'mysqldump', triedPaths: ['/nonexistent']));
    $mock->shouldReceive('gzip')->andReturn(fakeMysqlClientBin('gzip'));
    $this->app->instance(BinaryLocator::class, $mock);

    $exit = Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
    ]);

    $output = Artisan::output();
    expect($exit)->not->toBe(0)
        ->and($output)->toContain('未找到 mysqldump');
});

/** 用 fake handler 让 backup() 抛异常，命中 dump 失败告警分支 */
function fakeThrowingBackupService(): void
{
    bindSupportedBackupToolchain();
    $handler = Mockery::mock(BackupHandlerInterface::class);
    $handler->shouldReceive('backup')->andThrow(new RuntimeException('mysqldump 失败: 模拟 dump 错误'));

    $service = Mockery::mock(BackupService::class)->makePartial();
    $service->shouldReceive('makeHandler')->andReturn($handler);
    $service->shouldReceive('resolveRetainedTables')->andReturn([]);
    $service->shouldReceive('resolveRuntimeResetTables')->andReturn([]);
    app()->instance(BackupService::class, $service);
}

/** 捕获型 SystemAlert：记录 send 的 dedupeKey 与 clearDedupe 的 key */
function bkSpySystemAlert(): object
{
    $spy = new class
    {
        public int $sendCount = 0;

        public array $sentKeys = [];

        public array $clearedKeys = [];
    };
    $mock = Mockery::mock(SystemAlert::class);
    $mock->shouldReceive('send')->andReturnUsing(
        function ($category, $title, $message, $details = [], $dedupeKey = null, $ttl = 24, $fp = null) use ($spy) {
            $spy->sendCount++;
            $spy->sentKeys[] = $dedupeKey;

            return true;
        }
    );
    $mock->shouldReceive('clearDedupe')->andReturnUsing(function ($key) use ($spy) {
        $spy->clearedKeys[] = $key;
    });
    app()->instance(SystemAlert::class, $mock);

    return $spy;
}

test('数据库操作 mutex 被占时失败且不产生备份', function () {
    bindBackupDatabaseMutex(acquired: false);
    $spy = bkSpySystemAlert();

    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir]);

    expect($exit)->not->toBe(0)
        ->and(glob($this->testDir.'/backup_*.sql.gz') ?: [])->toBeEmpty()
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->sentKeys[0])->toBe('backup_lock_contention');
});

test('dump 失败时返回 FAILURE 并发送 backup_dump_error 告警', function () {
    fakeThrowingBackupService();
    bindEmptyBackupStructure(times: 1);
    $spy = bkSpySystemAlert();

    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir]);

    expect($exit)->not->toBe(0)
        ->and($spy->sendCount)->toBe(1)
        ->and($spy->sentKeys[0])->toBe('backup_dump_error');
});

test('成功后清理三个备份告警去重键', function () {
    fakeOkBackupService();
    bindEmptyBackupStructure();
    $spy = bkSpySystemAlert();

    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir]);

    expect($exit)->toBe(0)
        ->and($spy->clearedKeys)->toContain('backup_lock_contention')
        ->and($spy->clearedKeys)->toContain('backup_client_missing')
        ->and($spy->clearedKeys)->toContain('backup_dump_error');
});

test('本轮 SQL 使用随机 part 且不覆盖或删除旧固定 part 残留', function () {
    Carbon::setTestNow('2026-08-30 14:00:00');
    $legacyPart = $this->testDir.'/backup_20260830_140000.sql.gz.part';
    file_put_contents($legacyPart, 'legacy-part');

    try {
        fakeOkBackupService();
        bindEmptyBackupStructure();
        bkSpySystemAlert();

        $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);

        expect($exit)->toBe(0)
            ->and(file_get_contents($legacyPart))->toBe('legacy-part')
            ->and(glob($this->testDir.'/backup_20260830_140000.sql.gz'))->toHaveCount(1);
    } finally {
        Carbon::setTestNow();
    }
});

test('同一时间戳的两次失败备份使用不同的本轮 SQL part 路径', function () {
    Carbon::setTestNow('2026-08-30 14:00:00');
    $partPaths = [];

    try {
        bindSupportedBackupToolchain();
        bindBackupDatabaseMutex();
        bindEmptyBackupStructure(times: 2);
        bkSpySystemAlert();
        $handler = Mockery::mock(BackupHandlerInterface::class);
        $handler->shouldReceive('backup')->twice()->andReturnUsing(
            function (array $config, string $partPath) use (&$partPaths): never {
                $partPaths[] = $partPath;
                file_put_contents($partPath, 'partial');

                throw new RuntimeException('intentional failure');
            },
        );
        $service = Mockery::mock(BackupService::class)->makePartial();
        $service->shouldReceive('makeHandler')->andReturn($handler);
        $service->shouldReceive('resolveRetainedTables')->andReturn([]);
        $service->shouldReceive('resolveRuntimeResetTables')->andReturn([]);
        app()->instance(BackupService::class, $service);

        Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);
        Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);

        expect($partPaths)->toHaveCount(2)
            ->and($partPaths[0])->toEndWith('.sql.gz.part')
            ->and($partPaths[1])->toEndWith('.sql.gz.part')
            ->and(dirname($partPaths[0]))->toBe($this->testDir)
            ->and(dirname($partPaths[1]))->toBe($this->testDir)
            ->and($partPaths[0])->not->toBe($partPaths[1])
            ->and(is_file($partPaths[0]))->toBeFalse()
            ->and(is_file($partPaths[1]))->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('原子发布先写 part 与带 metadata 的 schema 再发布最终 SQL', function () {
    bindBackupDatabaseMutex();
    bindSupportedBackupToolchain();
    bindAtomicBackupService($this->testDir);
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportBackupStructure')->twice()->andReturn(atomicBackupStructure());
    app()->instance(DatabaseStructureService::class, $structure);
    bkSpySystemAlert();

    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);
    $sqlFiles = glob($this->testDir.'/backup_*.sql.gz') ?: [];
    $schemaFiles = glob($this->testDir.'/backup_*.schema.json') ?: [];
    $schema = json_decode((string) file_get_contents($schemaFiles[0] ?? ''), true);

    expect($exit)->toBe(0)
        ->and($sqlFiles)->toHaveCount(1)
        ->and($schemaFiles)->toHaveCount(1)
        ->and(glob($this->testDir.'/*.part') ?: [])->toBeEmpty()
        ->and($schema['backup_meta']['format_version'] ?? null)->toBe(2)
        ->and($schema['backup_meta']['stream']['compressed_bytes'] ?? null)->toBe(filesize($sqlFiles[0]))
        ->and($schema['backup_meta']['stream']['sha256'] ?? null)->toBe(hash_file('sha256', $sqlFiles[0]))
        ->and($schema['backup_meta']['included_tables'] ?? null)->toBe(['atomic_test'])
        ->and($schema['backup_meta']['excluded_tables'] ?? null)->toBe(['jobs'])
        ->and(array_keys($schema['backup_meta']['table_capacities'] ?? []))->toBe(['atomic_test'])
        ->and($schema['backup_meta'])->not->toHaveKeys(['retained_tables', 'runtime_reset_tables']);
});

test('备份 schema 与 dump 使用同一 ignore 集且保留未排除的 migrations', function () {
    bindBackupDatabaseMutex();
    bindSupportedBackupToolchain();
    $handlerIgnoreTables = null;
    $service = bindAtomicBackupService(
        $this->testDir,
        function (string $partPath, array $ignoreTables) use (&$handlerIgnoreTables): void {
            $handlerIgnoreTables = $ignoreTables;
        },
        ['activity_logs'],
        ['jobs'],
    );
    $physicalStructure = atomicBackupStructure();
    $table = $physicalStructure['tables']['atomic_test'];
    $physicalStructure['tables']['migrations'] = $table;
    $physicalStructure['tables']['jobs'] = $table;
    $physicalStructure['tables']['activity_logs'] = $table;
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportCurrentStructure')->never();
    $structure->shouldReceive('exportBackupStructure')->twice()->andReturn($physicalStructure);
    app()->instance(DatabaseStructureService::class, $structure);
    bkSpySystemAlert();

    $command = new BackupCommand(
        $structure,
        $service,
        app(BackupMetadataFactory::class),
        app(MysqlToolchainChecker::class),
        app(DatabaseOperationMutex::class),
    );
    $command->setLaravel(app());
    $exit = (new CommandTester($command))->execute(['--path' => $this->testDir, '--keep' => 0]);
    $schemaFiles = glob($this->testDir.'/backup_*.schema.json') ?: [];
    $schema = json_decode((string) file_get_contents($schemaFiles[0] ?? ''), true);

    expect($exit)->toBe(0)
        ->and($handlerIgnoreTables)->toBe(['activity_logs', 'jobs'])
        ->and(array_keys($schema['tables'] ?? []))->toBe(['atomic_test', 'migrations', 'jobs'])
        ->and($schema['backup_meta']['included_tables'] ?? null)->toBe(['atomic_test', 'migrations'])
        ->and($schema['backup_meta']['excluded_tables'] ?? null)->toBe(['activity_logs', 'jobs'])
        ->and(array_keys($schema['backup_meta']['table_capacities'] ?? []))->toBe(['atomic_test', 'migrations'])
        ->and($schema['backup_meta'])->not->toHaveKeys(['retained_tables', 'runtime_reset_tables'])
        ->and($schema['tables'] ?? [])->not->toHaveKey('activity_logs');
});

test('发布顺序为 Schema rename 后真实 fsync 目录、SQL rename 后再真实 fsync 目录', function () {
    bindBackupDatabaseMutex();
    bindSupportedBackupToolchain();
    bindAtomicBackupService($this->testDir);
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportBackupStructure')->twice()->andReturn(atomicBackupStructure());
    app()->instance(DatabaseStructureService::class, $structure);
    bkSpySystemAlert();

    $directorySyncSnapshots = [];
    $GLOBALS['backup_command_fsync_hook'] = function (mixed $stream) use (&$directorySyncSnapshots): bool {
        $uri = (string) (stream_get_meta_data($stream)['uri'] ?? '');
        if ($uri === $this->testDir) {
            $directorySyncSnapshots[] = [
                'schema' => count(glob($uri.'/backup_*.schema.json') ?: []),
                'sql' => count(glob($uri.'/backup_*.sql.gz') ?: []),
                'sql_part' => count(glob($uri.'/*.sql.gz.part') ?: []),
            ];
        }

        return fsync($stream);
    };
    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);

    expect($exit)->toBe(0)
        ->and($directorySyncSnapshots)->toBe([
            ['schema' => 1, 'sql' => 0, 'sql_part' => 1],
            ['schema' => 1, 'sql' => 1, 'sql_part' => 0],
        ]);
});

test('Schema rename 后目录 fsync 失败时不发布最终 SQL', function () {
    bindBackupDatabaseMutex();
    bindSupportedBackupToolchain();
    bindAtomicBackupService($this->testDir);
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportBackupStructure')->twice()->andReturn(atomicBackupStructure());
    app()->instance(DatabaseStructureService::class, $structure);
    bkSpySystemAlert();

    $GLOBALS['backup_command_fsync_hook'] = function (mixed $stream): bool {
        $uri = (string) (stream_get_meta_data($stream)['uri'] ?? '');
        if ($uri === $this->testDir) {
            return false;
        }

        return fsync($stream);
    };
    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('无法同步备份目录')
        ->and(glob($this->testDir.'/backup_*.schema.json') ?: [])->toHaveCount(1)
        ->and(glob($this->testDir.'/backup_*.sql.gz') ?: [])->toBeEmpty()
        ->and(glob($this->testDir.'/*.sql.gz.part') ?: [])->toBeEmpty();
});

test('第二次目录 fsync 失败时撤下本轮最终 SQL 且不影响既有备份或旧 part', function () {
    Carbon::setTestNow('2026-08-30 15:00:00');
    $existing = makeBackup($this->testDir, '20260829_120000', 0);
    $legacyPart = $this->testDir.'/backup_20260830_150000.sql.gz.part';
    file_put_contents($legacyPart, 'legacy-part');

    try {
        bindBackupDatabaseMutex();
        bindSupportedBackupToolchain();
        $service = bindAtomicBackupService($this->testDir);
        $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
        $structure->shouldReceive('exportBackupStructure')->twice()->andReturn(atomicBackupStructure());
        app()->instance(DatabaseStructureService::class, $structure);
        bkSpySystemAlert();

        $directorySyncCalls = 0;
        $firstDirectorySyncSucceeded = false;
        $GLOBALS['backup_command_fsync_hook'] = function (mixed $stream) use (&$directorySyncCalls, &$firstDirectorySyncSucceeded): bool {
            $uri = (string) (stream_get_meta_data($stream)['uri'] ?? '');
            if ($uri !== $this->testDir) {
                return fsync($stream);
            }

            $directorySyncCalls++;
            if ($directorySyncCalls === 2) {
                return false;
            }

            $synced = fsync($stream);
            if ($directorySyncCalls === 1) {
                $firstDirectorySyncSucceeded = $synced;
            }

            return $synced;
        };

        $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);
        $listedIds = array_column($service->listBackups(), 'id');

        expect($firstDirectorySyncSucceeded)->toBeTrue()
            ->and($directorySyncCalls)->toBeGreaterThanOrEqual(2)
            ->and($exit)->not->toBe(0)
            ->and(is_file($this->testDir.'/backup_20260830_150000.sql.gz'))->toBeFalse()
            ->and($listedIds)->toBe(['backup_20260829_120000'])
            ->and(file_get_contents($existing['sql']))->toBe('fake')
            ->and(file_get_contents($existing['schema']))->toBe('{"tables":[]}')
            ->and(file_get_contents($legacyPart))->toBe('legacy-part');
    } finally {
        Carbon::setTestNow();
    }
});

test('dump 前后结构语义变化时失败且没有最终 SQL 或 schema', function () {
    bindBackupDatabaseMutex();
    bindSupportedBackupToolchain();
    bindAtomicBackupService($this->testDir);
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportBackupStructure')
        ->twice()
        ->andReturn(atomicBackupStructure('int'), atomicBackupStructure('bigint'));
    app()->instance(DatabaseStructureService::class, $structure);
    bkSpySystemAlert();

    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);

    expect($exit)->not->toBe(0)
        ->and(glob($this->testDir.'/backup_*.sql.gz') ?: [])->toBeEmpty()
        ->and(glob($this->testDir.'/backup_*.schema.json') ?: [])->toBeEmpty()
        ->and(glob($this->testDir.'/*.part') ?: [])->toBeEmpty();
});

test('schema 序列化失败时清理 SQL part 且不发布最终文件', function () {
    bindBackupDatabaseMutex();
    bindSupportedBackupToolchain();
    bindAtomicBackupService($this->testDir);
    $invalid = atomicBackupStructure();
    $invalid['unencodable'] = "\xB1\x31";
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportBackupStructure')->twice()->andReturn($invalid);
    app()->instance(DatabaseStructureService::class, $structure);
    bkSpySystemAlert();

    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);

    expect($exit)->not->toBe(0)
        ->and(glob($this->testDir.'/backup_*.sql.gz') ?: [])->toBeEmpty()
        ->and(glob($this->testDir.'/backup_*.schema.json') ?: [])->toBeEmpty()
        ->and(glob($this->testDir.'/*.part') ?: [])->toBeEmpty();
});

test('SQL 最终 rename 失败时 schema 孤儿不会被列表识别为完整新版备份', function () {
    bindBackupDatabaseMutex();
    bindSupportedBackupToolchain();
    $service = bindAtomicBackupService($this->testDir, function (string $partPath): void {
        preg_match('/^(backup_\d{8}_\d{6})\.[a-f0-9]+\.sql\.gz\.part$/', basename($partPath), $matches);
        mkdir(dirname($partPath).'/'.$matches[1].'.sql.gz');
    });
    $structure = Mockery::mock(DatabaseStructureService::class)->makePartial();
    $structure->shouldReceive('exportBackupStructure')->twice()->andReturn(atomicBackupStructure());
    app()->instance(DatabaseStructureService::class, $structure);
    bkSpySystemAlert();

    $exit = Artisan::call('schedule:backup', ['--path' => $this->testDir, '--keep' => 0]);

    expect($exit)->not->toBe(0)
        ->and(glob($this->testDir.'/backup_*.schema.json') ?: [])->toHaveCount(1)
        ->and($service->listBackups())->toBeEmpty()
        ->and(glob($this->testDir.'/*.part') ?: [])->toBeEmpty();
});
