<?php

use App\Services\Backup\BackupHandlerInterface;
use App\Services\Backup\BackupService;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Support\Facades\Artisan;

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
 * 构造一个 pre_restore_ 文件及 schema.json，mtime 指定为 N 天前。
 */
function makePreRestore(string $dir, string $stamp, int $daysAgo): array
{
    $sql = $dir.'/pre_restore_'.$stamp.'.sql.gz';
    $schema = $dir.'/pre_restore_'.$stamp.'.schema.json';
    file_put_contents($sql, 'fake');
    file_put_contents($schema, '{"tables":[]}');
    $time = time() - $daysAgo * 86400;
    touch($sql, $time);
    touch($schema, $time);

    return ['sql' => $sql, 'schema' => $schema];
}

/**
 * 用 fake handler 替换 BackupService::makeHandler，让 ensureClient 跳过 mysqldump
 * 探测、backup 写一个 fake gz 文件即返回 —— 用于让命令在测试环境跑通到真正想验证的
 * 路径（清理逻辑、prefix 校验等），不依赖本地 mysqldump 是否在标准 PATH 上。
 *
 * 之前依赖 Symfony ExecutableFinder + 开发者 shell PATH 隐式找到 mysqldump 才能跑，
 * 是"开发机能跑、生产挂"的典型隐患。
 *
 * 用 makePartial：保留 BackupService 其他真实方法（如 filterStructureTables 在
 * writeSchemaJson 阶段被调用），只覆盖 makeHandler/resolveIgnoreTables 两个口。
 */
function fakeOkBackupService(): void
{
    $handler = Mockery::mock(BackupHandlerInterface::class);
    // 返回 fake 路径仅供 ensureClient 满足非空契约：backup() 整体被 mock，不会真去 exec 这条路径
    $handler->shouldReceive('ensureClient')->andReturn('/fake/mysqldump');
    $handler->shouldReceive('backup')->andReturnUsing(function ($cfg, $out) {
        $gz = gzopen($out, 'wb');
        gzwrite($gz, "-- fake\n");
        gzclose($gz);

        return $out;
    });

    $service = Mockery::mock(BackupService::class)->makePartial();
    $service->shouldReceive('makeHandler')->andReturn($handler);
    $service->shouldReceive('resolveIgnoreTables')->andReturn([]);
    app()->instance(BackupService::class, $service);
}

beforeEach(function () {
    $this->testDir = storage_path('databak_unit_'.uniqid());
    mkdir($this->testDir, 0755, true);
});

afterEach(function () {
    if (isset($this->testDir) && is_dir($this->testDir)) {
        foreach (glob($this->testDir.'/*') ?: [] as $f) {
            @unlink($f);
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
    $this->mock(DatabaseStructureService::class)
        ->shouldReceive('exportCurrentStructure')
        ->andReturn(['tables' => []]);

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
    $this->mock(DatabaseStructureService::class)
        ->shouldReceive('exportCurrentStructure')
        ->andReturn(['tables' => []]);

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

test('backup_ 天数清理不波及 pre_restore_（仅 backup_ 前缀参与天数清理）', function () {
    // 跑 backup 默认前缀的天数清理（--keep 30），pre_restore_ 不应被 purgeOldBackups 触及；
    // pre_restore_ 自身的数量上限清理只在 --prefix pre_restore 时触发，见下方独立用例。
    $preRestore = makeBackup($this->testDir, '20260101_000001', 365); // 1 年前
    // pre_restore_ 不是 makeBackup 造的，手动搞一个
    $sqlPre = $this->testDir.'/pre_restore_20260101_000001.sql.gz';
    rename($preRestore['sql'], $sqlPre);
    touch($sqlPre, time() - 365 * 86400);

    config(['database.backup.min_keep' => 0]);

    fakeOkBackupService();
    $this->mock(DatabaseStructureService::class)
        ->shouldReceive('exportCurrentStructure')
        ->andReturn(['tables' => []]);

    Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--keep' => 30,
    ]);

    expect(is_file($sqlPre))->toBeTrue();
});

test('pre_restore_ 超出 pre_restore_keep 时清理最旧、保留最近 N 份（成对删 schema.json）', function () {
    // 造 4 份 pre_restore（mtime 由旧到新错开）；命令再生成第 5 份（mtime=now，最新）。
    // pre_restore_keep=3 → 应只保留最近 3 份（新建 + 最近 2 份既有），删最旧 2 份。
    $f40 = makePreRestore($this->testDir, '20260101_000001', 40);
    $f30 = makePreRestore($this->testDir, '20260101_000002', 30);
    $f20 = makePreRestore($this->testDir, '20260101_000003', 20);
    $f10 = makePreRestore($this->testDir, '20260101_000004', 10);

    config(['database.backup.pre_restore_keep' => 3]);

    fakeOkBackupService();
    $this->mock(DatabaseStructureService::class)
        ->shouldReceive('exportCurrentStructure')
        ->andReturn(['tables' => []]);

    $exit = Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--prefix' => 'pre_restore',
        '--keep' => 0,
    ]);

    $remaining = glob($this->testDir.'/pre_restore_*.sql.gz') ?: [];

    expect($exit)->toBe(0)
        ->and(count($remaining))->toBe(3)
        // 最旧两份成对删除（含 schema.json）
        ->and(is_file($f40['sql']))->toBeFalse()
        ->and(is_file($f40['schema']))->toBeFalse()
        ->and(is_file($f30['sql']))->toBeFalse()
        ->and(is_file($f30['schema']))->toBeFalse()
        // 最近两份既有保留（连同新建共 3 份）
        ->and(is_file($f20['sql']))->toBeTrue()
        ->and(is_file($f10['sql']))->toBeTrue();
});

test('pre_restore_keep=0 时不限制 pre_restore 数量（保留全部）', function () {
    $a = makePreRestore($this->testDir, '20260101_000001', 40);
    $b = makePreRestore($this->testDir, '20260101_000002', 30);

    config(['database.backup.pre_restore_keep' => 0]);

    fakeOkBackupService();
    $this->mock(DatabaseStructureService::class)
        ->shouldReceive('exportCurrentStructure')
        ->andReturn(['tables' => []]);

    $exit = Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--prefix' => 'pre_restore',
        '--keep' => 0,
    ]);

    // 2 份既有 + 1 份新建 = 3 份全部保留
    $remaining = glob($this->testDir.'/pre_restore_*.sql.gz') ?: [];
    expect($exit)->toBe(0)
        ->and(count($remaining))->toBe(3)
        ->and(is_file($a['sql']))->toBeTrue()
        ->and(is_file($b['sql']))->toBeTrue();
});

test('--prefix 含非法字符（大写/数字/横线）时报错并中止', function () {
    // mysqldump 探测必须先通过才能跑到 prefix 校验（命令执行顺序：ensureClient → prefix）
    fakeOkBackupService();

    $exit = Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--prefix' => 'Bad-Name1',
    ]);

    $output = Artisan::output();
    expect($exit)->not->toBe(0)
        ->and($output)->toContain('--prefix 只能包含小写字母与下划线');
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
    $this->app->instance(BinaryLocator::class, $mock);

    $exit = Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
    ]);

    $output = Artisan::output();
    expect($exit)->not->toBe(0)
        ->and($output)->toContain('未找到 mysqldump 命令');
});
