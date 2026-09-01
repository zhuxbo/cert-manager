<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Composer\ComposerMirror;
use App\Services\Upgrade\BackupManager;
use App\Services\Upgrade\DatabaseStructureService;
use App\Services\Upgrade\EnvironmentChecker;
use App\Services\Upgrade\PackageExtractor;
use App\Services\Upgrade\ReleaseClient;
use App\Services\Upgrade\UpgradeService;
use App\Services\Upgrade\VersionManager;
use App\Support\Opcache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('structure warning message omits extra tables from upgrade actions', function () {
    $service = (new ReflectionClass(UpgradeService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(UpgradeService::class, 'buildStructureWarningMessage');

    $message = $method->invoke($service, [
        'missing_tables' => ['users_archive'],
        'extra_tables' => ['easy_logs', 'cloud_deploy_logs'],
    ]);

    expect($message)
        ->toBe('缺失表: users_archive')
        ->not->toContain('多余表', 'easy_logs', 'cloud_deploy_logs');
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
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class)
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
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class)
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
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class)
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
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class)
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
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class)
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
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class)
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
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class)
    );

    $result = $service->deleteBackup('backup_123');

    expect($result)->toBeTrue();
});

test('binary locator resolves composer command', function () {
    // 在开发环境中，composer 应该是可用的；BinaryLocator::composer() 返回完整 "{php} {phar}" 命令串
    $cmd = app(BinaryLocator::class)->composer();

    expect($cmd)->toBeString()->not->toBeEmpty();
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

// 后台升级由 UpgradeController::execute spawn `artisan upgrade:run &`，全程 CLI 子进程：
// opcache_reset 清的是子进程自己的字节码缓存，够不到 PHP-FPM。日志若只写 ok，
// 运维会据此认为线上字节码已换 —— 与 cache:clear-all 的 CLI 文案同源，必须带限定。

/** 造一个只控制 status/isCli 的 Opcache 替身 */
function fakeOpcacheReturning(string $status, bool $isCli): Opcache
{
    return new class($status, $isCli) extends Opcache
    {
        public function __construct(private string $fakeStatus, private bool $cli) {}

        public function reset(): array
        {
            return ['status' => $this->fakeStatus, 'reason' => null, 'message' => null, 'sapi' => $this->cli ? 'cli' : 'fpm-fcgi'];
        }

        public function isCli(): bool
        {
            return $this->cli;
        }
    };
}

function invokeResetOpcache(Opcache $opcache, string $context): void
{
    $service = new UpgradeService(
        Mockery::mock(VersionManager::class),
        Mockery::mock(ReleaseClient::class),
        Mockery::mock(BackupManager::class),
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class),
        new ComposerMirror,
        $opcache,
    );

    (new ReflectionClass($service))->getMethod('resetOpcache')->invoke($service, $context);
}

test('CLI 下 opcache 记账带 cli-only 限定（不冒充线上字节码已换）', function () {
    Log::spy();

    invokeResetOpcache(fakeOpcacheReturning(Opcache::OK, isCli: true), '[Upgrade] 步骤 9');

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => str_contains($message, 'cli-only') && str_contains($message, 'FPM 未受影响'))
        ->once();
});

test('FPM 进程内 opcache 记账不带 cli-only 限定', function () {
    Log::spy();

    invokeResetOpcache(fakeOpcacheReturning(Opcache::OK, isCli: false), '[Upgrade] 步骤 9');

    // 同时正向断言消息主体，避免消息退化成空串时这条否定断言恒绿
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => str_contains($message, '[Upgrade] 步骤 9 opcache: ok')
            && ! str_contains($message, 'cli-only'))
        ->once();
});

test('opcache 返回 failed 时记 warning 而非 info', function () {
    Log::spy();

    invokeResetOpcache(fakeOpcacheReturning(Opcache::FAILED, isCli: true), '[Rollback] 最终清理');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'opcache_reset 未生效'))
        ->once();
    Log::shouldNotHaveReceived('info');
});
