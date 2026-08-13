<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Plugin\PluginComposerRunner;
use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\UpgradePreflight;
use App\Services\Upgrade\VersionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
    foreach (glob(sys_get_temp_dir().'/pmch-*') as $dir) {
        File::deleteDirectory($dir);
    }
    File::deleteDirectory(storage_path('app/plugin-recovery'));
});

/**
 * 造一个带 PluginComposerRunner mock 的 PluginManager，pluginsPath/downloadPath 指向临时目录。
 *
 * @return array{0: PluginManager, 1: ReflectionClass, 2: string} [manager, reflection, pluginsPath]
 */
function makeManagerWithRunner(PluginComposerRunner $runner): array
{
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager, $runner);

    $pluginsPath = sys_get_temp_dir().'/pmch-plugins-'.uniqid();
    $downloadPath = sys_get_temp_dir().'/pmch-dl-'.uniqid();
    mkdir($pluginsPath, 0755, true);
    mkdir($downloadPath, 0755, true);

    $ref = new ReflectionClass($manager);
    $ref->getProperty('pluginsPath')->setValue($manager, $pluginsPath);
    $ref->getProperty('downloadPath')->setValue($manager, $downloadPath);

    return [$manager, $ref, $pluginsPath];
}

/**
 * 造一个插件 zip（含 plugin.json，可选 backend/composer.json），返回 zip 路径。
 */
function makePluginZip(string $name, bool $withComposer, array $manifest = [], ?string $wrapper = null): string
{
    $zipPath = sys_get_temp_dir().'/pmch-zip-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $prefix = $wrapper === null ? $name : "$wrapper/$name";
    $manifest = array_merge(['name' => $name, 'version' => '1.0.0'], $manifest);
    $zip->addFromString("$prefix/plugin.json", json_encode($manifest));
    if ($withComposer) {
        $zip->addFromString("$prefix/backend/composer.json", json_encode(['require' => ['php' => '^8.3']]));
    }
    $zip->close();

    return $zipPath;
}

// ==================== installFromZip — composer hook 跳过 / 触发 ====================

test('installFromZip 无 composer.json 的插件跳过 composer（runner.install 不被调用）', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    // 关键：无 composer.json → pluginHasComposer=false → install 绝不被调用
    $runner->shouldReceive('pluginHasComposer')->andReturn(false);
    $runner->shouldNotReceive('install');

    [$manager, , $pluginsPath] = makeManagerWithRunner($runner);

    $zip = makePluginZip('no-composer-plugin', withComposer: false);
    $result = $manager->installFromZip($zip);

    expect($result['name'])->toBe('no-composer-plugin');
    // 插件已落地
    expect(is_file("$pluginsPath/no-composer-plugin/plugin.json"))->toBeTrue();
});

test('installFromZip 有 composer.json 的插件触发 composer install', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(true);
    $runner->shouldReceive('bundledVendorMatchesLock')->andReturn(false);
    // 关键：有 composer.json → install 必被调用一次（带插件名）
    $runner->shouldReceive('install')
        ->once()
        ->withArgs(fn (string $dir, string $name) => str_ends_with($dir, '/has-composer-plugin') && $name === 'has-composer-plugin');

    [$manager, , $pluginsPath] = makeManagerWithRunner($runner);

    $zip = makePluginZip('has-composer-plugin', withComposer: true);
    $result = $manager->installFromZip($zip);

    expect($result['name'])->toBe('has-composer-plugin');
    expect(is_file("$pluginsPath/has-composer-plugin/backend/composer.json"))->toBeTrue();
});

test('installFromZip composer install 失败时清理半装目录 + 异常冒泡', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(true);
    $runner->shouldReceive('bundledVendorMatchesLock')->andReturn(false);
    $runner->shouldReceive('install')
        ->once()
        ->andThrow(new RuntimeException('插件 broken-plugin 依赖安装失败（composer install 退出码 1）'));

    [$manager, , $pluginsPath] = makeManagerWithRunner($runner);

    $zip = makePluginZip('broken-plugin', withComposer: true);

    expect(fn () => $manager->installFromZip($zip))
        ->toThrow(RuntimeException::class, '依赖安装失败');

    // 半装目录必须被清理，否则重装命中"已安装"、更新命中"已是最新"陷入死锁
    expect(is_dir("$pluginsPath/broken-plugin"))->toBeFalse();
});

test('installFromZip 命中"已安装"时不误删既有插件目录', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldNotReceive('install');

    [$manager, , $pluginsPath] = makeManagerWithRunner($runner);

    // 预置一个已安装的同名插件（含 plugin.json + 标记文件）
    $existing = "$pluginsPath/dup-plugin";
    mkdir($existing, 0755, true);
    file_put_contents("$existing/plugin.json", json_encode(['name' => 'dup-plugin', 'version' => '1.0.0']));
    file_put_contents("$existing/keep.txt", 'must-not-be-deleted');

    $zip = makePluginZip('dup-plugin', withComposer: false);

    expect(fn () => $manager->installFromZip($zip))
        ->toThrow(RuntimeException::class, '已安装');

    // 既有目录及其内容不能被清理（$applied 守卫：本次未落地，不删他人目录）
    expect(is_file("$existing/keep.txt"))->toBeTrue();
});

test('installFromZip 与在线安装一致校验插件包 requires', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldNotReceive('install');

    [$manager] = makeManagerWithRunner($runner);
    $reflection = new ReflectionClass($manager);
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getVersionString')->once()->andReturn('0.6.2');
    $reflection->getProperty('versionManager')->setValue($manager, $versionManager);

    $zip = makePluginZip(
        'requires-plugin',
        withComposer: false,
        manifest: ['requires' => '>=0.6.3'],
    );

    expect(fn () => $manager->installFromZip($zip))
        ->toThrow(RuntimeException::class, '请先升级系统');
});

test('installFromZip 使用与在线安装一致的解压校验进度阶段', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(false);
    $runner->shouldNotReceive('install');

    [$manager] = makeManagerWithRunner($runner);
    $stages = [];
    $manager = $manager->withProgressReporter(function (string $stage) use (&$stages): void {
        $stages[] = $stage;
    });

    $manager->installFromZip(makePluginZip('progress-plugin', withComposer: false));

    expect($stages)->toContain('extracting', 'validating', 'applying');
});

test('installFromZip 接受与在线安装相同的双层包装目录', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(false);
    $runner->shouldNotReceive('install');

    [$manager, , $pluginsPath] = makeManagerWithRunner($runner);

    $result = $manager->installFromZip(
        makePluginZip('wrapped-plugin', withComposer: false, wrapper: 'release-bundle'),
    );

    expect($result['name'])->toBe('wrapped-plugin')
        ->and(is_file("$pluginsPath/wrapped-plugin/plugin.json"))->toBeTrue();
});

test('install 在线路径通过共享的插件包安装流程正常落地', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(false);
    $runner->shouldNotReceive('install');

    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getVersionString')->twice()->andReturn('1.0.0');
    $zip = makePluginZip(
        'online-plugin',
        withComposer: false,
        manifest: ['requires' => '>=0.6.3'],
    );

    $manager = new class($versionManager, $runner, $zip) extends PluginManager
    {
        public function __construct($versionManager, $runner, private string $zip)
        {
            parent::__construct($versionManager, $runner);
        }

        protected function fetchRemoteReleases(string $baseUrl): array
        {
            return [[
                'tag_name' => 'v1.0.0',
                'requires' => '>=0.6.3',
                'assets' => [[
                    'name' => 'online-plugin-plugin-1.0.0.zip',
                    'browser_download_url' => 'https://example.com/online-plugin.zip',
                ]],
            ]];
        }

        protected function downloadPlugin(string $url, string $savePath): void
        {
            copy($this->zip, $savePath);
        }
    };

    $pluginsPath = sys_get_temp_dir().'/pmch-plugins-'.uniqid();
    $downloadPath = sys_get_temp_dir().'/pmch-dl-'.uniqid();
    mkdir($pluginsPath, 0755, true);
    mkdir($downloadPath, 0755, true);

    $reflection = new ReflectionClass(PluginManager::class);
    $reflection->getProperty('pluginsPath')->setValue($manager, $pluginsPath);
    $reflection->getProperty('downloadPath')->setValue($manager, $downloadPath);

    $result = $manager->install('online-plugin', 'https://example.com/plugins/online-plugin');

    expect($result['version'])->toBe('1.0.0')
        ->and(is_file("$pluginsPath/online-plugin/plugin.json"))->toBeTrue();
});

// ==================== installPluginComposerDeps（install 路径共用）跳过 / 触发 ====================

test('installPluginComposerDeps 无 composer.json 跳过、有则触发', function () {
    // case A: 无 composer.json
    $runnerA = Mockery::mock(PluginComposerRunner::class);
    $runnerA->shouldReceive('pluginHasComposer')->andReturn(false);
    $runnerA->shouldNotReceive('install');
    [$managerA, $refA] = makeManagerWithRunner($runnerA);
    $methodA = $refA->getMethod('installPluginComposerDeps');
    $methodA->invoke($managerA, 'plug-a', '/tmp/whatever-a');
    expect(true)->toBeTrue(); // 未抛、install 未被调用

    // case B: 有 composer.json
    $runnerB = Mockery::mock(PluginComposerRunner::class);
    $runnerB->shouldReceive('pluginHasComposer')->andReturn(true);
    $runnerB->shouldReceive('bundledVendorMatchesLock')->andReturn(false);
    $runnerB->shouldReceive('install')->once()->with('/tmp/whatever-b', 'plug-b');
    [$managerB, $refB] = makeManagerWithRunner($runnerB);
    $methodB = $refB->getMethod('installPluginComposerDeps');
    $methodB->invoke($managerB, 'plug-b', '/tmp/whatever-b');
});

test('installPluginComposerDeps 包内 vendor 已与 lock 对齐时不调用 composer', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(true);
    $runner->shouldReceive('bundledVendorMatchesLock')->once()->andReturn(true);
    $runner->shouldNotReceive('install');
    [$manager, $reflection] = makeManagerWithRunner($runner);

    $method = $reflection->getMethod('installPluginComposerDeps');
    $method->invoke($manager, 'cloud-deploy', '/tmp/cloud-deploy');
});

test('installPluginComposerDeps 包内 vendor 存在但校验失败时不尝试联网修复', function () {
    $pluginDir = sys_get_temp_dir().'/pmch-invalid-vendor-'.uniqid();
    File::ensureDirectoryExists("$pluginDir/backend/vendor");
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(true);
    $runner->shouldReceive('bundledVendorMatchesLock')->once()->andReturn(false);
    $runner->shouldNotReceive('install');
    [$manager, $reflection] = makeManagerWithRunner($runner);

    $method = $reflection->getMethod('installPluginComposerDeps');
    expect(fn () => $method->invoke($manager, 'cloud-deploy', $pluginDir))
        ->toThrow(RuntimeException::class, '包内 vendor 与 composer.lock 不匹配');
});

// ==================== update — 按 composer.lock 哈希决定是否重装 ====================

/**
 * 可测试的 PluginManager 子类：拦掉 update 内部的网络 / 下载，
 * 让 update 走到「删旧版 → 移入新版 → composer.lock 对比」这段真实逻辑。
 *
 * 真实 PluginComposerRunner 注入（用其 pluginHasComposer/lockHash 真实实现），
 * 但 install() 被覆盖为「记录调用次数」——避免真跑 composer，同时验证决策正确。
 */
function makeUpdateManager(
    string $newLockContent,
    bool $withBundledVendor = false,
    bool $withInvalidBundledVendor = false,
): array {
    $installCalls = new stdClass;
    $installCalls->count = 0;
    $installCalls->reporterWasPassed = false;

    // 真实 runner，但 install 覆盖为计数（不真跑 composer）
    $runner = new class(app(BinaryLocator::class), app(UpgradePreflight::class), $installCalls) extends PluginComposerRunner
    {
        public function __construct($l, $p, private stdClass $calls)
        {
            parent::__construct($l, $p);
        }

        public function install(string $pluginDir, string $name, ?callable $reporter = null): void
        {
            $this->calls->count++;
            $this->calls->reporterWasPassed = $reporter !== null;
        }
    };

    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getVersionString')->andReturn('99.0.0'); // 兼容性检查恒通过

    $pluginsPath = sys_get_temp_dir().'/pmch-plugins-'.uniqid();
    $downloadPath = sys_get_temp_dir().'/pmch-dl-'.uniqid();
    mkdir($pluginsPath, 0755, true);
    mkdir($downloadPath, 0755, true);

    // 新版 zip：版本 2.0.0 + backend/composer.json + 指定 composer.lock 内容
    $newZip = sys_get_temp_dir().'/pmch-newzip-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($newZip, ZipArchive::CREATE);
    $zip->addFromString('lock-plugin/plugin.json', json_encode(['name' => 'lock-plugin', 'version' => '2.0.0']));
    $zip->addFromString('lock-plugin/backend/composer.json', json_encode(['require' => ['php' => '^8.3']]));
    $zip->addFromString('lock-plugin/backend/composer.lock', $newLockContent);
    if ($withBundledVendor || $withInvalidBundledVendor) {
        $zip->addFromString('lock-plugin/backend/vendor/autoload.php', '<?php return true;');
        $zip->addFromString(
            'lock-plugin/backend/vendor/composer/.ssl-manager-lock.sha256',
            ($withInvalidBundledVendor ? hash('sha256', 'OTHER-LOCK') : hash('sha256', $newLockContent))."\n"
        );
        $zip->addFromString('lock-plugin/backend/vendor/new-package.php', 'new');
    }
    $zip->close();

    $manager = new class($versionManager, $runner, $newZip) extends PluginManager
    {
        public function __construct($vm, $runner, private string $newZip)
        {
            parent::__construct($vm, $runner);
        }

        // 拦网络：返回 2.0.0 release
        protected function fetchRemoteReleases(string $baseUrl): array
        {
            return [['tag_name' => 'v2.0.0', 'assets' => [['name' => 'lock-plugin-plugin-2.0.0.zip', 'browser_download_url' => 'https://example.com/lock-plugin-plugin-2.0.0.zip']]]];
        }

        // 拦下载：把预备好的新版 zip 拷到目标路径
        protected function downloadPlugin(string $url, string $savePath): void
        {
            copy($this->newZip, $savePath);
        }
    };

    $ref = new ReflectionClass(PluginManager::class);
    $ref->getProperty('pluginsPath')->setValue($manager, $pluginsPath);
    $ref->getProperty('downloadPath')->setValue($manager, $downloadPath);

    return [$manager, $pluginsPath, $installCalls];
}

/**
 * 在 pluginsPath 下预置「已安装」的 lock-plugin v1.0.0（带指定 composer.lock）。
 */
function seedInstalledPlugin(string $pluginsPath, string $lockContent, bool $withVendor = true): void
{
    $dir = "$pluginsPath/lock-plugin";
    mkdir("$dir/backend", 0755, true);
    file_put_contents("$dir/plugin.json", json_encode([
        'name' => 'lock-plugin',
        'version' => '1.0.0',
        'release_url' => 'https://example.com/lock-plugin',
    ]));
    file_put_contents("$dir/backend/composer.json", json_encode(['require' => ['php' => '^8.3']]));
    file_put_contents("$dir/backend/composer.lock", $lockContent);
    // 模拟运行时装好的 vendor（带标记文件，便于断言"复用"而非"重装"）
    if ($withVendor) {
        mkdir("$dir/backend/vendor", 0755, true);
        file_put_contents("$dir/backend/vendor/.runtime-installed", 'old-vendor-marker');
    }
}

test('update composer.lock 未变化时跳过 composer install（复用原 vendor）', function () {
    [$manager, $pluginsPath, $installCalls] = makeUpdateManager(newLockContent: 'SAME-LOCK');
    seedInstalledPlugin($pluginsPath, lockContent: 'SAME-LOCK'); // 默认带 vendor

    $result = $manager->update('lock-plugin');

    expect($result['version'])->toBe('2.0.0');
    // lock 内容相同 + 有可复用 vendor → install 不被调用
    expect($installCalls->count)->toBe(0);
    // 原 vendor 被移回复用（标记文件还在），不会因删旧目录而丢失
    expect(is_file("$pluginsPath/lock-plugin/backend/vendor/.runtime-installed"))->toBeTrue();
});

test('update composer.lock 未变化但 vendor 缺失时仍触发 install（防 vendor 永久丢失）', function () {
    [$manager, $pluginsPath, $installCalls] = makeUpdateManager(newLockContent: 'SAME-LOCK');
    seedInstalledPlugin($pluginsPath, lockContent: 'SAME-LOCK', withVendor: false);

    $result = $manager->update('lock-plugin');

    expect($result['version'])->toBe('2.0.0');
    // lock 未变但无 vendor 可复用 → 必须安装，否则补丁更新后 vendor 永久缺失
    expect($installCalls->count)->toBe(1);
});

test('update composer.lock 变化时触发 composer install', function () {
    [$manager, $pluginsPath, $installCalls] = makeUpdateManager(newLockContent: 'NEW-LOCK');
    seedInstalledPlugin($pluginsPath, lockContent: 'OLD-LOCK');

    $result = $manager->update('lock-plugin');

    expect($result['version'])->toBe('2.0.0');
    // lock 内容变化 → 哈希不同 → install 被调用一次
    expect($installCalls->count)->toBe(1);
});

test('update composer.lock 变化但新包自带对齐 vendor 时不运行 composer', function () {
    [$manager, $pluginsPath, $installCalls] = makeUpdateManager(
        newLockContent: 'NEW-LOCK',
        withBundledVendor: true,
    );
    seedInstalledPlugin($pluginsPath, lockContent: 'OLD-LOCK');

    $result = $manager->update('lock-plugin');

    expect($result['version'])->toBe('2.0.0')
        ->and($installCalls->count)->toBe(0)
        ->and("$pluginsPath/lock-plugin/backend/vendor/new-package.php")->toBeFile()
        ->and("$pluginsPath/lock-plugin/backend/vendor/.runtime-installed")->not->toBeFile();
});

test('update 在删除旧插件前拒绝不匹配的包内 vendor', function () {
    [$manager, $pluginsPath, $installCalls] = makeUpdateManager(
        newLockContent: 'NEW-LOCK',
        withInvalidBundledVendor: true,
    );
    seedInstalledPlugin($pluginsPath, lockContent: 'OLD-LOCK');

    expect(fn () => $manager->update('lock-plugin'))
        ->toThrow(RuntimeException::class, '包内 vendor 与 composer.lock 不匹配');

    $manifest = json_decode(file_get_contents("$pluginsPath/lock-plugin/plugin.json"), true);
    expect($manifest['version'])->toBe('1.0.0')
        ->and("$pluginsPath/lock-plugin/backend/vendor/.runtime-installed")->toBeFile()
        ->and($installCalls->count)->toBe(0);
});

test('update 重装 composer 依赖时传递进度 reporter', function () {
    [$manager, $pluginsPath, $installCalls] = makeUpdateManager(newLockContent: 'NEW-LOCK');
    seedInstalledPlugin($pluginsPath, lockContent: 'OLD-LOCK');

    $manager = $manager->withProgressReporter(fn () => null);
    $result = $manager->update('lock-plugin');

    expect($result['version'])->toBe('2.0.0');
    expect($installCalls->count)->toBe(1)
        ->and($installCalls->reporterWasPassed)->toBeTrue();
});

test('update 迁移回滚不干净时隔离失败新目录并恢复旧目录', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('lockHash')->andReturn('');
    $runner->shouldReceive('pluginHasComposer')->andReturn(false);
    $runner->shouldNotReceive('install');

    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getVersionString')->andReturn('99.0.0');

    $pluginsPath = sys_get_temp_dir().'/pmch-plugins-'.uniqid();
    $downloadPath = sys_get_temp_dir().'/pmch-dl-'.uniqid();
    mkdir($pluginsPath, 0755, true);
    mkdir($downloadPath, 0755, true);

    $pluginDir = "$pluginsPath/lock-plugin";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", json_encode([
        'name' => 'lock-plugin',
        'version' => '1.0.0',
        'release_url' => 'https://example.com/lock-plugin',
    ]));
    file_put_contents("$pluginDir/old.txt", 'old');

    $newZip = sys_get_temp_dir().'/pmch-newzip-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($newZip, ZipArchive::CREATE);
    $zip->addFromString('lock-plugin/plugin.json', json_encode(['name' => 'lock-plugin', 'version' => '2.0.0']));
    $zip->addFromString('lock-plugin/new.txt', 'new');
    $zip->close();

    $manager = new class($versionManager, $runner, $newZip) extends PluginManager
    {
        public function __construct($vm, $runner, private string $newZip)
        {
            parent::__construct($vm, $runner);
        }

        protected function fetchRemoteReleases(string $baseUrl): array
        {
            return [['tag_name' => 'v2.0.0', 'assets' => [['name' => 'lock-plugin-plugin-2.0.0.zip', 'browser_download_url' => 'https://example.com/lock-plugin-plugin-2.0.0.zip']]]];
        }

        protected function downloadPlugin(string $url, string $savePath): void
        {
            copy($this->newZip, $savePath);
        }

        protected function runPluginMigrations(string $name): void
        {
            throw new RuntimeException('migrate failed');
        }

        protected function rollbackNewPluginMigrations(string $name, array $before): bool
        {
            return false;
        }
    };

    $ref = new ReflectionClass(PluginManager::class);
    $ref->getProperty('pluginsPath')->setValue($manager, $pluginsPath);
    $ref->getProperty('downloadPath')->setValue($manager, $downloadPath);

    expect(fn () => $manager->update('lock-plugin'))
        ->toThrow(RuntimeException::class, 'migrate failed');

    $recoveryDirs = glob(storage_path('app/plugin-recovery/lock-plugin-*')) ?: [];

    expect(is_file("$pluginsPath/lock-plugin/old.txt"))->toBeTrue()
        ->and(is_file("$pluginsPath/lock-plugin/new.txt"))->toBeFalse()
        ->and($recoveryDirs)->not->toBeEmpty()
        ->and(is_file($recoveryDirs[0].'/new.txt'))->toBeTrue()
        ->and(glob("$downloadPath/plugin-backup-lock-plugin-*") ?: [])->toBeEmpty();
});

test('异步下载路径将 download timeout 作为 curl 和 HTTP fallback 总预算', function () {
    config(['plugin.download.timeout' => 30]);

    $runner = Mockery::mock(PluginComposerRunner::class);
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new class($versionManager, $runner) extends PluginManager
    {
        public int $curlTimeout = 0;

        public function exposeDownload(string $url, string $savePath): void
        {
            $this->downloadPlugin($url, $savePath);
        }

        protected function downloadWithCurl(string $url, string $savePath, int $timeout): bool
        {
            $this->curlTimeout = $timeout;

            return false;
        }
    };

    $downloadDir = sys_get_temp_dir().'/pmch-download-'.uniqid();
    mkdir($downloadDir, 0755, true);
    $savePath = "$downloadDir/plugin.zip";

    Http::fake(function () use ($savePath) {
        file_put_contents($savePath, 'zip');

        return Http::response('ok');
    });

    $manager = $manager->withProgressReporter(fn () => null);
    $manager->exposeDownload('https://example.com/plugin.zip', $savePath);

    expect($manager->curlTimeout)->toBe(15)
        ->and(is_file($savePath))->toBeTrue();
});
