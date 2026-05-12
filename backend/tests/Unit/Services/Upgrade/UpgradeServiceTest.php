<?php

use App\Services\Upgrade\BackupManager;
use App\Services\Upgrade\DatabaseStructureService;
use App\Services\Upgrade\PackageExtractor;
use App\Services\Upgrade\ReleaseClient;
use App\Services\Upgrade\UpgradeService;
use App\Services\Upgrade\VersionManager;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('check for update returns no update when same version', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getCurrentVersion')->andReturn([
        'version' => '1.0.0',
        'channel' => 'main',
    ]);
    $versionManager->shouldReceive('compareVersions')
        ->with('1.0.0', '1.0.0')
        ->andReturn(0);

    $releaseClient = Mockery::mock(ReleaseClient::class);
    $releaseClient->shouldReceive('getLatestRelease')
        ->with('main')
        ->andReturn([
            'version' => '1.0.0',
            'body' => 'Release notes',
            'published_at' => '2026-01-01',
        ]);
    $releaseClient->shouldReceive('findUpgradePackageUrl')->andReturn(null);

    $service = new UpgradeService(
        $versionManager,
        $releaseClient,
        Mockery::mock(BackupManager::class),
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class)
    );

    $result = $service->checkForUpdate();

    expect($result['has_update'])->toBeFalse();
    expect($result['current_version'])->toBe('1.0.0');
    expect($result['latest_version'])->toBe('1.0.0');
});

test('check for update returns update available', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getCurrentVersion')->andReturn([
        'version' => '1.0.0',
        'channel' => 'main',
    ]);
    $versionManager->shouldReceive('compareVersions')
        ->with('1.1.0', '1.0.0')
        ->andReturn(1);

    $releaseClient = Mockery::mock(ReleaseClient::class);
    $releaseClient->shouldReceive('getLatestRelease')
        ->with('main')
        ->andReturn([
            'version' => '1.1.0',
            'body' => 'New features',
            'published_at' => '2026-01-10',
        ]);
    $releaseClient->shouldReceive('findUpgradePackageUrl')
        ->andReturn('https://example.com/upgrade.zip');

    $service = new UpgradeService(
        $versionManager,
        $releaseClient,
        Mockery::mock(BackupManager::class),
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class)
    );

    $result = $service->checkForUpdate();

    expect($result['has_update'])->toBeTrue();
    expect($result['current_version'])->toBe('1.0.0');
    expect($result['latest_version'])->toBe('1.1.0');
    expect($result['changelog'])->toBe('New features');
});

test('check for update handles no release', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getCurrentVersion')->andReturn([
        'version' => '1.0.0',
        'channel' => 'main',
    ]);

    $releaseClient = Mockery::mock(ReleaseClient::class);
    $releaseClient->shouldReceive('getLatestRelease')
        ->with('main')
        ->andReturn(null);

    $service = new UpgradeService(
        $versionManager,
        $releaseClient,
        Mockery::mock(BackupManager::class),
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class)
    );

    $result = $service->checkForUpdate();

    expect($result['has_update'])->toBeFalse();
    expect($result['latest_version'])->toBeNull();
    expect($result['message'])->toBe('无法获取最新版本信息');
});

test('get release history returns releases', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getChannel')->andReturn('main');

    $releases = [
        ['version' => '1.1.0', 'tag_name' => 'v1.1.0'],
        ['version' => '1.0.0', 'tag_name' => 'v1.0.0'],
    ];

    $releaseClient = Mockery::mock(ReleaseClient::class);
    $releaseClient->shouldReceive('getReleaseHistory')
        ->with(5, 'main')
        ->andReturn($releases);

    $service = new UpgradeService(
        $versionManager,
        $releaseClient,
        Mockery::mock(BackupManager::class),
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class)
    );

    $result = $service->getReleaseHistory(5);

    expect($result)->toHaveCount(2);
    expect($result[0]['version'])->toBe('1.1.0');
});

test('get backups returns backup list', function () {
    $backups = [
        ['id' => 'backup_1', 'created_at' => '2026-01-01'],
        ['id' => 'backup_2', 'created_at' => '2026-01-02'],
    ];

    $backupManager = Mockery::mock(BackupManager::class);
    $backupManager->shouldReceive('listBackups')->andReturn($backups);

    $service = new UpgradeService(
        Mockery::mock(VersionManager::class),
        Mockery::mock(ReleaseClient::class),
        $backupManager,
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class)
    );

    $result = $service->getBackups();

    expect($result)->toHaveCount(2);
});

test('rollback fails for missing backup', function () {
    $backupManager = Mockery::mock(BackupManager::class);
    $backupManager->shouldReceive('getBackup')
        ->with('invalid_backup')
        ->once()
        ->andReturn(null);

    $service = new UpgradeService(
        Mockery::mock(VersionManager::class),
        Mockery::mock(ReleaseClient::class),
        $backupManager,
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class)
    );

    $result = $service->rollback('invalid_backup');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('备份不存在');
});

test('delete backup calls backup manager', function () {
    $backupManager = Mockery::mock(BackupManager::class);
    $backupManager->shouldReceive('deleteBackup')
        ->with('backup_123')
        ->once()
        ->andReturn(true);

    $service = new UpgradeService(
        Mockery::mock(VersionManager::class),
        Mockery::mock(ReleaseClient::class),
        $backupManager,
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class)
    );

    $result = $service->deleteBackup('backup_123');

    expect($result)->toBeTrue();
});

test('find composer command', function () {
    $service = app(UpgradeService::class);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('findComposerCommand');

    $result = $method->invoke($service);

    // 在开发环境中，composer 应该是可用的
    expect($result)->not->toBeNull();
});

test('check network access method', function () {
    $service = app(UpgradeService::class);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('checkNetworkAccess');

    // 测试本地地址
    $result = $method->invoke($service, 'http://localhost', 1);

    // 本地可能有或没有服务运行，所以只验证方法执行不报错
    expect($result)->toBeBool();
});
