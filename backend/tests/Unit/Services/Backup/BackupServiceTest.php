<?php

use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// installHintLines 读 config('database.default')；Unit 默认不挂 TestCase，需启动 Laravel app
uses(TestCase::class);

test('resolveRetainedTables 只返回当前数据库的动态日志表', function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mysql',
    ]);
    DB::shouldReceive('select')
        ->once()
        ->with(Mockery::type('string'), ['ssl_manager_test'])
        ->andReturn([
            (object) ['TABLE_NAME' => 'admin_logs'],
            (object) ['TABLE_NAME' => 'activity_logs'],
        ]);

    expect((new BackupService)->resolveRetainedTables('ssl_manager_test'))
        ->toBe(['admin_logs', 'activity_logs']);
});

test('resolveRetainedTables 不为 MariaDB 目标启用恢复兼容路径', function () {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mariadb',
    ]);
    DB::shouldReceive('select')->never();

    expect((new BackupService)->resolveRetainedTables('ssl_manager_test'))->toBe([]);
});

test('resolveRuntimeResetTables 只返回当前存在的固定运行时表', function () {
    Schema::shouldReceive('hasTable')->andReturnUsing(
        fn (string $table): bool => in_array($table, ['jobs', 'sessions'], true),
    );

    expect((new BackupService)->resolveRuntimeResetTables())->toBe(['jobs', 'sessions']);
});

test('filterStructureTables 剔除指定表', function () {
    $svc = new BackupService;
    $structure = [
        'tables' => [
            'users' => ['columns' => []],
            'admin_logs' => ['columns' => []],
            'jobs' => ['columns' => []],
            'orders' => ['columns' => []],
        ],
    ];

    $filtered = $svc->filterStructureTables($structure, ['admin_logs', 'jobs']);

    expect(array_keys($filtered['tables']))->toEqual(['users', 'orders']);
});

test('filterStructureTables 在空 ignore 列表时原样返回', function () {
    $svc = new BackupService;
    $structure = ['tables' => ['users' => ['columns' => []]]];

    expect($svc->filterStructureTables($structure, []))->toEqual($structure);
});

test('filterStructureTables 不影响其它顶层字段', function () {
    $svc = new BackupService;
    $structure = [
        'tables' => ['x' => ['c' => []], 'y' => ['c' => []]],
        'generated_at' => '2026-04-25 00:00:00',
    ];

    $filtered = $svc->filterStructureTables($structure, ['x']);

    expect($filtered['generated_at'])->toBe('2026-04-25 00:00:00')
        ->and(array_keys($filtered['tables']))->toEqual(['y']);
});

test('installHintLines: mysql driver 返回 mysql-client 安装提示', function () {
    $lines = BackupService::installHintLines('mysql');
    $message = implode("\n", $lines);

    expect($lines)->toBeArray()->not->toBeEmpty();
    expect($message)->toContain('mysql-client')
        ->toContain('Oracle MySQL')
        ->toContain('同系列')
        ->toContain('apt-get update && apt-get install -y mysql-client')
        ->toContain('dnf install -y mysql-community-client')
        ->toContain('不要使用')
        ->toContain('default-mysql-client')
        ->toContain('MariaDB');
});

test('installHintLines: 不传 driver 时回落到当前 default connection 的 driver', function () {
    // default 已被测试环境设置为 mysql（.env），不传参时应返回 mysql 提示
    if (config('database.connections.'.config('database.default').'.driver') !== 'mysql') {
        test()->markTestSkipped('当前 default 不是 mysql');
    }
    $lines = BackupService::installHintLines();
    expect(implode("\n", $lines))->toContain('mysql-client');
});

test('resolveBackup: 非法 ID 格式返回 null（防路径穿越）', function () {
    $svc = new BackupService;

    expect($svc->resolveBackup('../../../etc/passwd'))->toBeNull()
        ->and($svc->resolveBackup('backup_short'))->toBeNull()
        ->and($svc->resolveBackup('BACKUP_20260101_000000'))->toBeNull()  // 大写不匹配
        ->and($svc->resolveBackup('backup_20260101_00000a'))->toBeNull(); // 非纯数字
});

test('备份列表和读取忽略 .part，且新版只有最终 SQL 才算完成', function () {
    $directory = storage_path('framework/testing/backup-service-artifacts');
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    array_map('unlink', glob($directory.'/*') ?: []);

    $service = new class($directory) extends BackupService
    {
        public function __construct(private string $directory) {}

        public function basePath(): string
        {
            return $this->directory;
        }
    };

    $id = 'backup_20260830_130000';
    file_put_contents("$directory/$id.sql.gz.part", 'partial');
    file_put_contents("$directory/$id.schema.json", json_encode(['backup_meta' => ['format_version' => 2]]));

    expect($service->listBackups())->toBeEmpty()
        ->and($service->resolveBackup($id))->toBeNull()
        ->and($service->readSchema($id))->toBeNull();

    $gzip = gzopen("$directory/$id.sql.gz", 'wb');
    gzwrite($gzip, 'SELECT 1;');
    gzclose($gzip);

    expect($service->listBackups())->toHaveCount(1)
        ->and($service->resolveBackup($id))->not->toBeNull();

    array_map('unlink', glob($directory.'/*') ?: []);
    rmdir($directory);
});
