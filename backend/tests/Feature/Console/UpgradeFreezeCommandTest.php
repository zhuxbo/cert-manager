<?php

use App\Utils\UpgradeFreezeLock;

uses()->group('database');

beforeEach(function () {
    UpgradeFreezeLock::unfreeze('restore');
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze('restore');
});

// ==========================================
// 1. 默认参数：ttl=7200，无 from/to
// ==========================================

test('upgrade:freeze 无参数时默认 ttl=7200', function () {
    $this->artisan('upgrade:freeze')
        ->expectsOutputToContain('升级冻结锁已写入')
        ->assertSuccessful();

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    $info = UpgradeFreezeLock::info();
    expect($info)->not->toBeNull();
    expect($info['version_from'])->toBeNull();
    expect($info['version_to'])->toBeNull();
    expect($info['ttl_seconds'])->toBe(7200);
});

// ==========================================
// 2. 完整参数：from / to / ttl 全部生效
// ==========================================

test('upgrade:freeze --from --to --ttl 完整字段写入锁文件', function () {
    $this->artisan('upgrade:freeze', [
        '--from' => '1.0.0',
        '--to' => '1.1.0',
        '--ttl' => 3600,
    ])->assertSuccessful();

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    $info = UpgradeFreezeLock::info();
    expect($info)->not->toBeNull();
    expect($info['version_from'])->toBe('1.0.0');
    expect($info['version_to'])->toBe('1.1.0');
    expect($info['ttl_seconds'])->toBe(3600);
});

// ==========================================
// 3. ttl < 60 报错（exit code 非 0）
// ==========================================

test('upgrade:freeze --ttl=30 < 60 时命令失败', function () {
    $this->artisan('upgrade:freeze', ['--ttl' => 30])
        ->expectsOutputToContain('--ttl 必须在 60 ~ 7200 范围内')
        ->assertFailed();

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

// ==========================================
// 4. ttl > 7200 报错
// ==========================================

test('upgrade:freeze --ttl=10000 > 7200 时命令失败', function () {
    $this->artisan('upgrade:freeze', ['--ttl' => 10000])
        ->expectsOutputToContain('--ttl 必须在 60 ~ 7200 范围内')
        ->assertFailed();

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

// ==========================================
// 5. ttl 非数字报错
// ==========================================

test('upgrade:freeze --ttl=abc 时命令失败', function () {
    $this->artisan('upgrade:freeze', ['--ttl' => 'abc'])
        ->expectsOutputToContain('--ttl 必须是整数')
        ->assertFailed();

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

// ==========================================
// 6. upgrade:unfreeze 删除锁文件
// ==========================================

test('upgrade:unfreeze 删除锁文件，HTTP 维护态解除', function () {
    UpgradeFreezeLock::freeze('1.0.0', '1.1.0');
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    $this->artisan('upgrade:unfreeze')
        ->expectsOutputToContain('升级冻结锁已删除')
        ->assertSuccessful();

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
    expect(file_exists(UpgradeFreezeLock::path()))->toBeFalse();
});

// ==========================================
// 7. upgrade:unfreeze 在未 freeze 状态下也成功（幂等）
// ==========================================

test('upgrade:unfreeze 在未 freeze 状态下也成功', function () {
    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();

    $this->artisan('upgrade:unfreeze')->assertSuccessful();

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

// ==========================================
// 5. 锁归属：--source 缺省 shell、白名单校验
// ==========================================

test('upgrade:freeze 默认 source=shell，--source 白名单校验', function () {
    $this->artisan('upgrade:freeze')->assertSuccessful();

    $info = UpgradeFreezeLock::info();
    expect($info['owner_source'])->toBe('shell')
        ->and($info['owner_pid'])->toBeInt();

    UpgradeFreezeLock::unfreeze();
    $this->artisan('upgrade:freeze', ['--source' => 'web'])->assertSuccessful();
    expect(UpgradeFreezeLock::info()['owner_source'])->toBe('web');

    UpgradeFreezeLock::unfreeze();
    $this->artisan('upgrade:freeze', ['--source' => 'bogus'])->assertFailed();
    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

test('upgrade:freeze 不得把现有恢复锁误报为写入成功', function () {
    expect(UpgradeFreezeLock::freezeRestore('restore-in-progress'))->toBeTrue();

    $this->artisan('upgrade:freeze')
        ->expectsOutputToContain('可能有数据库恢复正在执行')
        ->assertFailed();

    expect(UpgradeFreezeLock::info()['owner_source'] ?? null)->toBe('restore');
});
