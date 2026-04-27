<?php

use App\Services\Backup\BackupService;

// ensureMysqlClient 依赖 config()，需要启动 Laravel app（Unit 默认不挂 TestCase）
uses(Tests\TestCase::class);

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

test('ensureMysqlClient: 配置为不存在的绝对路径时抛带安装提示的异常', function () {
    config(['database.backup.mysqldump_bin' => '/nonexistent/path/to/mysqldump']);

    $svc = new BackupService;

    expect(fn () => $svc->ensureMysqlClient('mysqldump'))
        ->toThrow(RuntimeException::class, 'mysqldump 不可执行');
});

test('ensureMysqlClient: PATH 中找不到二进制时抛简短异常', function () {
    config(['database.backup.mysql_bin' => '__definitely_not_exist_xyz__']);

    $svc = new BackupService;

    expect(fn () => $svc->ensureMysqlClient('mysql'))
        ->toThrow(RuntimeException::class, '未找到 mysql 命令');
});

test('installHintLines: 返回平台相关的多行安装提示', function () {
    $lines = BackupService::installHintLines();

    expect($lines)->toBeArray()->not->toBeEmpty();
    expect(implode("\n", $lines))->toContain('mysql-client');
});

test('ensureMysqlClient: 配置为合法 mysql 客户端绝对路径时返回该路径', function () {
    $bin = fakeMysqlClientBin('mysqldump');
    config(['database.backup.mysqldump_bin' => $bin]);

    $svc = new BackupService;
    expect($svc->ensureMysqlClient('mysqldump'))->toBe($bin);
});

test('ensureMysqlClient: 探测不区分 is_executable，可绕过 open_basedir', function () {
    // 行为锚点：probeExecutable 用 proc_open 而非 is_executable / file_exists；
    // 一个看起来像 mysql 客户端但不在 open_basedir 白名单的脚本仍能被识别。
    $bin = fakeMysqlClientBin('mysqldump');
    config(['database.backup.mysqldump_bin' => $bin]);

    $svc = new BackupService;
    expect($svc->ensureMysqlClient('mysqldump'))->toBe($bin);
});

test('ensureMysqlClient: 输出不含 mysql 客户端特征的脚本视为不可用', function () {
    $bogus = sys_get_temp_dir().'/bogus_'.uniqid().'.sh';
    file_put_contents($bogus, "#!/bin/sh\necho 'I am not mysqldump'\n");
    chmod($bogus, 0755);
    register_shutdown_function(static fn () => @unlink($bogus));

    config(['database.backup.mysqldump_bin' => $bogus]);
    $svc = new BackupService;

    expect(fn () => $svc->ensureMysqlClient('mysqldump'))
        ->toThrow(RuntimeException::class, 'mysqldump 不可执行');
});

test('resolveBackup: 非法 ID 格式返回 null（防路径穿越）', function () {
    $svc = new BackupService;

    expect($svc->resolveBackup('../../../etc/passwd'))->toBeNull()
        ->and($svc->resolveBackup('backup_short'))->toBeNull()
        ->and($svc->resolveBackup('BACKUP_20260101_000000'))->toBeNull()  // 大写不匹配
        ->and($svc->resolveBackup('backup_20260101_00000a'))->toBeNull(); // 非纯数字
});
