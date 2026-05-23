<?php

use App\Services\Backup\BackupService;
use Tests\TestCase;

// installHintLines 读 config('database.default')；Unit 默认不挂 TestCase，需启动 Laravel app
uses(TestCase::class);

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

    expect($lines)->toBeArray()->not->toBeEmpty();
    expect(implode("\n", $lines))->toContain('mysql-client');
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
