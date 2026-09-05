<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Upgrade\BackupManager;
use App\Services\Upgrade\DatabaseStructureService;
use App\Services\Upgrade\EnvironmentChecker;
use App\Services\Upgrade\PackageExtractor;
use App\Services\Upgrade\ReleaseClient;
use App\Services\Upgrade\UpgradeService;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Services\Upgrade\VersionManager;
use App\Utils\UpgradeFreezeLock;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

/**
 * H2：升级链路接入 freeze（危险窗挡 HTTP 写）+ H1：catch(\Throwable) 接住 \Error。
 *
 * 用 Artisan facade mock 拦 down/up/queue:restart，捕获每次调用时的 isFrozen 状态，
 * 既不触碰真实维护文件（并行安全），又能机器验证「unfreeze 严格先于 up」的顺序契约。
 */

/** 全部依赖 mock 成功跑到 apply；applyUpgrade 行为由调用方注入（成功回调 / 抛异常） */
function h2MakeService(Closure $applyBehavior, bool|array $bundledVendor = false, bool $composerFails = false): UpgradeService
{
    $tmp = sys_get_temp_dir();

    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getVersionString')->andReturn('v1.0.0');
    $versionManager->shouldReceive('getChannel')->andReturn('main');
    $versionManager->shouldReceive('isUpgradeAllowed')->andReturn(true);
    $versionManager->shouldReceive('checkPhpVersion')->andReturn(true);
    $versionManager->shouldReceive('getVersionPath')->andReturn($tmp.'/h2_version_'.uniqid().'.json');

    $releaseClient = Mockery::mock(ReleaseClient::class);
    $releaseClient->shouldReceive('getLatestRelease')->andReturn(['version' => 'v1.1.0']);
    $releaseClient->shouldReceive('getReleaseByTag')->andReturn(['version' => 'v1.1.0']);
    $releaseClient->shouldReceive('downloadUpgradePackage')->andReturn(true);

    $packageExtractor = Mockery::mock(PackageExtractor::class);
    $packageExtractor->shouldReceive('getDownloadPath')->andReturn($tmp);
    $packageExtractor->shouldReceive('extract')->andReturn($tmp.'/h2_extracted');
    $packageExtractor->shouldReceive('validatePackage')->andReturn(true);
    $packageExtractor->shouldReceive('findRequirementsJson')->andReturnNull();
    $packageExtractor->shouldReceive('applyUpgrade')->andReturnUsing($applyBehavior);
    $bundledValues = is_array($bundledVendor) ? $bundledVendor : [$bundledVendor];
    $packageExtractor->shouldReceive('appliedBundledVendor')->andReturn(...$bundledValues);
    $packageExtractor->shouldReceive('cleanup')->andReturnNull();
    $packageExtractor->shouldReceive('cleanupOldPackages')->andReturn(0);

    $environmentChecker = Mockery::mock(EnvironmentChecker::class);
    $environmentChecker->shouldReceive('check')->andReturn(['ok' => true]);

    $arguments = [
        $versionManager,
        $releaseClient,
        Mockery::mock(BackupManager::class),
        $packageExtractor,
        Mockery::mock(DatabaseStructureService::class),
        $environmentChecker,
    ];

    if (! $composerFails) {
        return new UpgradeService(...$arguments);
    }

    return new class(...$arguments) extends UpgradeService
    {
        protected function hasComposerChanges(array $oldHashes, array $newHashes): bool
        {
            return true;
        }

        protected function runComposerInstall(): bool
        {
            return false;
        }
    };
}

/** BinaryLocator 返回无害的 shell true，让 dump-autoload / package:discover 的 exec 变 no-op */
function h2FakeBinary(): void
{
    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('php')->andReturn('true');
    $mock->shouldReceive('composer')->andReturn('true');
    app()->instance(BinaryLocator::class, $mock);
}

beforeEach(function () {
    Config::set('upgrade.behavior.force_backup', false);
    Config::set('upgrade.behavior.maintenance_mode', true);
    Config::set('upgrade.behavior.auto_migrate', false);
    Config::set('upgrade.behavior.auto_seed', false);
    Config::set('upgrade.behavior.auto_structure_check', false);
    Config::set('upgrade.behavior.clear_cache', false);
    UpgradeFreezeLock::unfreeze('restore');
    (new UpgradeStatusManager)->clear();
    File::deleteDirectory(storage_path('app/legacy-platform-config'));
    h2FakeBinary();
});

afterEach(function () {
    Mockery::close();
    UpgradeFreezeLock::unfreeze('restore');
    (new UpgradeStatusManager)->clear();
    File::deleteDirectory(storage_path('app/legacy-platform-config'));
});

test('H2-A 成功升级：apply 期间 freeze 生效，unfreeze 严格先于 up，结束已解冻', function () {
    $frozenDuringApply = null;
    $callLog = [];
    Artisan::shouldReceive('call')->andReturnUsing(function ($command, $params = []) use (&$callLog) {
        $callLog[] = ['cmd' => $command, 'frozen' => UpgradeFreezeLock::isFrozen()];

        return 0;
    });

    $lockDuringApply = null;
    $service = h2MakeService(function () use (&$frozenDuringApply, &$lockDuringApply) {
        $frozenDuringApply = UpgradeFreezeLock::isFrozen();
        $lockDuringApply = UpgradeFreezeLock::info();

        return true; // applyUpgrade(): bool
    });

    $sm = new UpgradeStatusManager;
    $sm->start('v1.0.0'); // 真实流程由 UpgradeRunCommand 先 start，再 performUpgradeWithStatus
    $result = $service->performUpgradeWithStatus('latest', $sm);

    expect($result['success'])->toBeTrue()
        ->and($frozenDuringApply)->toBeTrue();          // 危险窗内 freeze 已点火
    // 锁归属：web 升级进程本体持锁（owner_pid == 本进程 == status.pid），watchdog 据此判自愈资格
    expect($lockDuringApply['owner_source'])->toBe('web')
        ->and($lockDuringApply['owner_pid'])->toBe(getmypid());
    expect(UpgradeFreezeLock::isFrozen())->toBeFalse(); // 结束已解冻

    // 顺序契约：'up' 调用时刻 freeze 必须已解除（否则 up 唤醒 worker → release 烧 attempts）
    $up = collect($callLog)->firstWhere('cmd', 'up');
    expect($up)->not->toBeNull();
    expect($up['frozen'])->toBeFalse();
    // down 必须在 up 之前
    $downIdx = collect($callLog)->search(fn ($c) => $c['cmd'] === 'down');
    $upIdx = collect($callLog)->search(fn ($c) => $c['cmd'] === 'up');
    expect($downIdx)->toBeLessThan($upIdx);
});

test('后台升级在覆盖代码前固定原 Redis 编号，同库则不进入 apply', function (int $cacheDatabase) {
    $directory = storage_path('redis-upgrade-env');
    File::ensureDirectoryExists($directory);
    File::put("$directory/.env", "APP_NAME=old_manager\n");
    $oldPath = app()->environmentPath();
    $oldFile = app()->environmentFile();
    app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');
    Config::set('cache.default', 'redis');
    Config::set('database.redis.default', ['database' => 0]);
    Config::set('database.redis.cache', ['database' => $cacheDatabase]);
    Artisan::shouldReceive('call')->andReturn(0);
    $applied = false;

    try {
        $service = h2MakeService(function () use (&$applied, $directory) {
            $applied = true;
            expect(Dotenv::parse(File::get("$directory/.env")))->toMatchArray([
                'APP_NAME' => 'old_manager', 'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1',
            ]);

            return true;
        }, bundledVendor: true);
        $sm = new UpgradeStatusManager;
        $sm->start('v1.0.0');
        $result = $service->performUpgradeWithStatus('latest', $sm);
        expect($result['success'])->toBe($cacheDatabase !== 0)
            ->and($applied)->toBe($cacheDatabase !== 0);
        if ($cacheDatabase === 0) {
            expect($result['error'])->toContain('相同');
        }
    } finally {
        app()->useEnvironmentPath($oldPath)->loadEnvironmentFrom($oldFile);
        File::deleteDirectory($directory);
    }
})->with([0, 1]);

test('restore 持锁时升级在备份和维护模式前停止且不覆盖 owner', function () {
    Config::set('upgrade.behavior.force_backup', true);
    Artisan::shouldReceive('call')->never();
    expect(UpgradeFreezeLock::freezeRestore('database restore'))->toBeTrue();

    $service = h2MakeService(fn () => true);
    $sm = new UpgradeStatusManager;
    $sm->start('v1.0.0');
    $result = $service->performUpgradeWithStatus('latest', $sm);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('无法取得升级冻结锁')
        ->and(UpgradeFreezeLock::info()['owner_source'])->toBe('restore');
});

test('升级包已携带与 lock 对齐的 vendor 时不依赖 Composer 也能完成', function () {
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldNotReceive('composer');
    app()->instance(BinaryLocator::class, $locator);
    Artisan::shouldReceive('call')->andReturn(0);

    $service = h2MakeService(fn () => true, bundledVendor: true);
    $sm = new UpgradeStatusManager;
    $sm->start('v1.0.0');

    $result = $service->performUpgradeWithStatus('latest', $sm);

    expect($result['success'])->toBeTrue();
});

test('旧代码首次 Composer 失败后退出维护，用户可以再次升级由新代码自愈', function () {
    Artisan::shouldReceive('call')->andReturn(0);
    $service = h2MakeService(
        fn () => true,
        bundledVendor: [false, true],
        composerFails: true,
    );

    $firstStatus = new UpgradeStatusManager;
    $firstStatus->start('v1.0.0');
    $first = $service->performUpgradeWithStatus('latest', $firstStatus);

    expect($first['success'])->toBeFalse()
        ->and($firstStatus->get()['status'])->toBe('failed')
        ->and(UpgradeFreezeLock::isFrozen())->toBeFalse();

    $firstStatus->clear();
    $secondStatus = new UpgradeStatusManager;
    $secondStatus->start('v1.0.0');
    $second = $service->performUpgradeWithStatus('latest', $secondStatus);

    expect($second['success'])->toBeTrue()
        ->and($secondStatus->get()['status'])->toBe('completed')
        ->and(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

test('H2-B / H1 apply 抛 TypeError：catch(\Throwable) 接住，失败路径 unfreeze + up，status failed', function () {
    $upCalled = false;
    Artisan::shouldReceive('call')->andReturnUsing(function ($command, $params = []) use (&$upCalled) {
        if ($command === 'up') {
            $upCalled = true;
        }

        return 0;
    });

    $service = h2MakeService(function () {
        throw new TypeError('模拟 \Error 中断（catch(\Exception) 接不住）');
    });

    $sm = new UpgradeStatusManager;
    $sm->start('v1.0.0'); // 真实流程由 UpgradeRunCommand 先 start，再 performUpgradeWithStatus
    $result = $service->performUpgradeWithStatus('latest', $sm);

    // \Throwable 未接住则 TypeError 逃逸、本行不可达、测试直接 error
    expect($result['success'])->toBeFalse()
        ->and($sm->get()['status'])->toBe('failed')
        ->and(UpgradeFreezeLock::isFrozen())->toBeFalse()  // 失败不滞留冻结
        ->and($upCalled)->toBeTrue();                       // 维护模式已退出
});

test('平台旧配置在 seed 成功后才清理', function () {
    Config::set('upgrade.behavior.auto_migrate', true);
    Config::set('upgrade.behavior.auto_seed', true);
    File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
    File::put(storage_path('app/legacy-platform-config/user.json'), '{"Title":"legacy"}');

    $callLog = [];
    Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$callLog) {
        $callLog[] = $command;
        if ($command === 'db:seed') {
            expect(storage_path('app/legacy-platform-config/user.json'))->toBeFile();
        }

        return 0;
    });

    $service = h2MakeService(fn () => true);
    $sm = new UpgradeStatusManager;
    $sm->start('v1.0.0');
    $result = $service->performUpgradeWithStatus('latest', $sm);

    expect($result['success'])->toBeTrue()
        ->and(storage_path('app/legacy-platform-config'))->not->toBeDirectory()
        ->and(array_search('migrate', $callLog, true))->toBeLessThan(array_search('db:seed', $callLog, true));
});

test('seed 失败时中止升级并保留平台旧配置', function () {
    Config::set('upgrade.behavior.auto_migrate', true);
    Config::set('upgrade.behavior.auto_seed', true);
    File::ensureDirectoryExists(storage_path('app/legacy-platform-config'));
    File::put(storage_path('app/legacy-platform-config/user.json'), '{"Title":"legacy"}');

    Artisan::shouldReceive('call')->andReturnUsing(function ($command) {
        if ($command === 'db:seed') {
            throw new RuntimeException('seed failed');
        }

        return 0;
    });

    $service = h2MakeService(fn () => true);
    $sm = new UpgradeStatusManager;
    $sm->start('v1.0.0');
    $result = $service->performUpgradeWithStatus('latest', $sm);

    expect($result['success'])->toBeFalse()
        ->and(storage_path('app/legacy-platform-config/user.json'))->toBeFile();
});

test('H2-C rollback 清除滞留 freeze（防御性清理，rollback 自身不 freeze）', function () {
    Artisan::shouldReceive('call')->andReturn(0);

    $backupManager = Mockery::mock(BackupManager::class);
    $backupManager->shouldReceive('getBackup')->with('backup_x')->andReturn(['version' => 'v1.0.0']);
    $backupManager->shouldReceive('restoreBackup')->with('backup_x')->andReturn(true);

    $service = new UpgradeService(
        Mockery::mock(VersionManager::class),
        Mockery::mock(ReleaseClient::class),
        $backupManager,
        Mockery::mock(PackageExtractor::class),
        Mockery::mock(DatabaseStructureService::class),
        Mockery::mock(EnvironmentChecker::class),
    );

    UpgradeFreezeLock::freeze('v1.1.0', 'v1.0.0', 3600); // 预置滞留冻结
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    $result = $service->rollback('backup_x');

    expect($result['success'])->toBeTrue()
        ->and(UpgradeFreezeLock::isFrozen())->toBeFalse();
});
