<?php

use App\Services\Backup\BackupService;
use Symfony\Component\Process\ExecutableFinder;

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

test('ensureMysqlClient: PATH 中找不到二进制时提示按平台安装', function () {
    config(['database.backup.mysql_bin' => '__definitely_not_exist_xyz__']);

    $svc = new BackupService;

    try {
        $svc->ensureMysqlClient('mysql');
        expect(true)->toBeFalse('应当抛出异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('未找到 mysql 命令')
            ->and($e->getMessage())->toContain('mysql-client'); // 安装提示中至少出现一次
    }
});

test('ensureMysqlClient: 二进制存在时返回真实路径', function () {
    // 用本机已有的 sh 假装是 mysqldump，仅测路径返回逻辑
    $finder = new ExecutableFinder;
    $sh = $finder->find('sh');
    if ($sh === null) {
        $this->markTestSkipped('本机无 sh，跳过');
    }
    config(['database.backup.mysqldump_bin' => $sh]);

    $svc = new BackupService;
    expect($svc->ensureMysqlClient('mysqldump'))->toBe($sh);
});

test('resolveBackup: 非法 ID 格式返回 null（防路径穿越）', function () {
    $svc = new BackupService;

    expect($svc->resolveBackup('../../../etc/passwd'))->toBeNull()
        ->and($svc->resolveBackup('backup_short'))->toBeNull()
        ->and($svc->resolveBackup('BACKUP_20260101_000000'))->toBeNull()  // 大写不匹配
        ->and($svc->resolveBackup('backup_20260101_00000a'))->toBeNull(); // 非纯数字
});
