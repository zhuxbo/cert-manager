<?php

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

    // 不触发实际备份（跳过 mysqldump），只测清理 — 用外部 path + keep=30 + 不创建新的
    // 但 schedule:backup 会创建一个新的；我们改为直接调用 purge 逻辑的简化方式：
    // 通过 --path 指向独立目录，再让命令做清理
    // 简化：直接运行 handle() 不现实，手动造文件 + 命令 +keep=30，命令会创建新文件后清理旧的
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

test('pre_restore_ 前缀永不自动清理', function () {
    $preRestore = makeBackup($this->testDir, '20260101_000001', 365); // 1 年前
    // pre_restore_ 不是 makeBackup 造的，手动搞一个
    $sqlPre = $this->testDir.'/pre_restore_20260101_000001.sql.gz';
    rename($preRestore['sql'], $sqlPre);
    touch($sqlPre, time() - 365 * 86400);

    config(['database.backup.min_keep' => 0]);

    $this->mock(DatabaseStructureService::class)
        ->shouldReceive('exportCurrentStructure')
        ->andReturn(['tables' => []]);

    Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
        '--keep' => 30,
    ]);

    expect(is_file($sqlPre))->toBeTrue();
});

test('--prefix 含非法字符（大写/数字/横线）时报错并中止', function () {
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

test('mysqldump 不可用（绝对路径不存在）时立即中止', function () {
    if (config('database.connections.'.config('database.default').'.driver') !== 'mysql') {
        test()->markTestSkipped('mysql-only');
    }

    config(['database.backup.mysqldump_bin' => '/nonexistent/path/to/mysqldump']);

    $exit = Artisan::call('schedule:backup', [
        '--path' => $this->testDir,
    ]);

    $output = Artisan::output();
    expect($exit)->not->toBe(0)
        ->and($output)->toContain('mysqldump 不可执行');
});
