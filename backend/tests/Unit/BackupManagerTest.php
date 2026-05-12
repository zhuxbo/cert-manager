<?php

use App\Services\Upgrade\BackupManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->testBackupPath = storage_path('test-backups');

    Config::set('upgrade.backup.path', $this->testBackupPath);
    Config::set('upgrade.backup.max_backups', 3);
    Config::set('upgrade.backup.include', [
        'backend' => false,
        'database' => false,
        'frontend' => false,
    ]);
});

afterEach(function () {
    // 清理测试备份目录
    if (File::isDirectory($this->testBackupPath)) {
        File::deleteDirectory($this->testBackupPath);
    }
});

test('validate backup id valid', function () {
    $manager = new BackupManager;

    // 有效的备份 ID 应该不抛出异常
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateBackupId');

    // 不抛出异常即为通过
    $method->invoke($manager, '2025-01-15_120000');
    $method->invoke($manager, '2024-12-31_235959');

    expect(true)->toBeTrue();
});

test('validate backup id invalid format', function () {
    $manager = new BackupManager;

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateBackupId');

    $method->invoke($manager, '../../../etc/passwd');
})->throws(RuntimeException::class, '无效的备份 ID');

test('validate backup id path traversal', function () {
    $manager = new BackupManager;

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateBackupId');

    $invalidIds = [
        '../2025-01-15_120000',
        '2025-01-15_120000/../..',
        '..\\..\\etc',
        'foo/bar',
        '',
        '2025-01-15',
        '120000',
    ];

    foreach ($invalidIds as $id) {
        try {
            $method->invoke($manager, $id);
            throw new Exception("Expected exception for invalid ID: $id");
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('无效的备份 ID');
        }
    }
});

test('list backups empty', function () {
    $manager = new BackupManager;

    $backups = $manager->listBackups();

    expect($backups)->toBeArray()->toBeEmpty();
});

test('get backup not exists', function () {
    $manager = new BackupManager;

    $backup = $manager->getBackup('2025-01-15_120000');

    expect($backup)->toBeNull();
});

test('delete backup not exists', function () {
    $manager = new BackupManager;

    $result = $manager->deleteBackup('2025-01-15_120000');

    expect($result)->toBeFalse();
});

test('create backup minimal', function () {
    // 禁用所有备份内容，只测试备份流程
    Config::set('upgrade.backup.include', [
        'backend' => false,
        'database' => false,
        'frontend' => false,
    ]);

    $manager = new BackupManager;
    $backupId = $manager->createBackup();

    // 验证备份 ID 格式
    expect($backupId)->toMatch('/^\d{4}-\d{2}-\d{2}_\d{6}$/');

    // 验证备份目录创建
    expect(File::isDirectory("$this->testBackupPath/$backupId"))->toBeTrue();

    // 验证备份信息文件
    expect(File::exists("$this->testBackupPath/$backupId/backup.json"))->toBeTrue();

    // 验证可以获取备份信息
    $info = $manager->getBackup($backupId);
    expect($info)->not->toBeNull();
    expect($info['id'])->toBe($backupId);
});

test('list backups sorted by date', function () {
    Config::set('upgrade.backup.include', [
        'backend' => false,
        'database' => false,
        'frontend' => false,
    ]);

    $manager = new BackupManager;

    // 创建多个备份
    $id1 = $manager->createBackup();
    sleep(1);
    $id2 = $manager->createBackup();

    $backups = $manager->listBackups();

    expect($backups)->toHaveCount(2);
    // 应该按时间倒序排列
    expect($backups[0]['id'])->toBe($id2);
    expect($backups[1]['id'])->toBe($id1);
});

test('clean old backups', function () {
    Config::set('upgrade.backup.max_backups', 2);
    Config::set('upgrade.backup.include', [
        'backend' => false,
        'database' => false,
        'frontend' => false,
    ]);

    $manager = new BackupManager;

    // 创建 3 个备份
    $id1 = $manager->createBackup();
    sleep(1);
    $id2 = $manager->createBackup();
    sleep(1);
    $id3 = $manager->createBackup();

    // 最老的备份应该被清理
    $backups = $manager->listBackups();
    expect($backups)->toHaveCount(2);

    $backupIds = array_column($backups, 'id');
    expect($backupIds)->toContain($id3);
    expect($backupIds)->toContain($id2);
    expect($backupIds)->not->toContain($id1);
});

test('delete backup', function () {
    Config::set('upgrade.backup.include', [
        'backend' => false,
        'database' => false,
        'frontend' => false,
    ]);

    $manager = new BackupManager;
    $backupId = $manager->createBackup();

    expect(File::isDirectory("$this->testBackupPath/$backupId"))->toBeTrue();

    $result = $manager->deleteBackup($backupId);

    expect($result)->toBeTrue();
    expect(File::isDirectory("$this->testBackupPath/$backupId"))->toBeFalse();
});
