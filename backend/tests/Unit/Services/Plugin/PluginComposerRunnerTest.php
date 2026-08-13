<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Plugin\PluginComposerRunner;
use App\Services\Upgrade\UpgradePreflight;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
    foreach (glob(sys_get_temp_dir().'/pcr-test-*') as $dir) {
        File::deleteDirectory($dir);
    }
    File::deleteDirectory(storage_path('app/plugin-composer'));
});

/**
 * 造一个临时插件目录；$withComposer 控制是否写 backend/composer.json，
 * $lockContent 非 null 时写 backend/composer.lock。
 */
function makePluginDir(bool $withComposer = true, ?string $lockContent = null): string
{
    $dir = sys_get_temp_dir().'/pcr-test-'.uniqid();
    mkdir("$dir/backend", 0755, true);
    if ($withComposer) {
        file_put_contents("$dir/backend/composer.json", json_encode(['require' => ['php' => '^8.3']]));
    }
    if ($lockContent !== null) {
        file_put_contents("$dir/backend/composer.lock", $lockContent);
    }

    return $dir;
}

/** preflight 返回「全通过」的桩。 */
function passingPreflight(): UpgradePreflight
{
    $preflight = Mockery::mock(UpgradePreflight::class);
    $preflight->shouldReceive('check')->andReturn([
        'blocking' => [],
        'items' => [],
        'ini' => ['fpm' => [], 'cli' => []],
    ]);

    return $preflight;
}

// ==================== pluginHasComposer ====================

test('pluginHasComposer 有 composer.json 返回 true、无返回 false', function () {
    $runner = new PluginComposerRunner(Mockery::mock(BinaryLocator::class), passingPreflight());

    $withComposer = makePluginDir(withComposer: true);
    $withoutComposer = makePluginDir(withComposer: false);

    expect($runner->pluginHasComposer($withComposer))->toBeTrue();
    expect($runner->pluginHasComposer($withoutComposer))->toBeFalse();
});

// ==================== lockHash ====================

test('lockHash 返回 composer.lock 的 sha256，不存在返回空串', function () {
    $runner = new PluginComposerRunner(Mockery::mock(BinaryLocator::class), passingPreflight());

    $noLock = makePluginDir(lockContent: null);
    expect($runner->lockHash($noLock))->toBe('');

    $withLock = makePluginDir(lockContent: 'LOCK-A');
    expect($runner->lockHash($withLock))->toBe(hash('sha256', 'LOCK-A'));

    // 内容不同 → 哈希不同（更新流程据此判定是否重装）
    $withLockB = makePluginDir(lockContent: 'LOCK-B');
    expect($runner->lockHash($withLockB))->not->toBe($runner->lockHash($withLock));
});

test('bundledVendorMatchesLock 仅接受 autoload 和锁文件标记完整的包内 vendor', function () {
    $runner = new PluginComposerRunner(Mockery::mock(BinaryLocator::class), passingPreflight());
    $pluginDir = makePluginDir(lockContent: 'LOCK-A');
    File::ensureDirectoryExists("$pluginDir/backend/vendor/composer");
    File::put("$pluginDir/backend/vendor/autoload.php", '<?php return true;');
    File::put(
        "$pluginDir/backend/vendor/composer/.ssl-manager-lock.sha256",
        hash('sha256', 'LOCK-A')."\n"
    );

    expect($runner->bundledVendorMatchesLock($pluginDir))->toBeTrue();

    File::put("$pluginDir/backend/composer.lock", 'LOCK-B');
    expect($runner->bundledVendorMatchesLock($pluginDir))->toBeFalse();
});

// ==================== install — 命令构造（不真跑 composer）====================

test('install 在 backend 目录跑 composer install，命令含 --no-dev 且路径已 escape', function () {
    $locator = Mockery::mock(BinaryLocator::class);
    // composer() 返回已 escape 的 php phar 前缀（模拟真实形态）
    $locator->shouldReceive('composer')->andReturn("'/usr/bin/php' '/usr/local/bin/composer'");

    // 子类覆盖 runShell（捕获命令、返回成功）+ 镜像探测（强制不切换，避免真发网络）
    $runner = new class($locator, passingPreflight()) extends PluginComposerRunner
    {
        public array $commands = [];

        protected function runShell(string $command): array
        {
            $this->commands[] = $command;

            return [0, 'ok'];
        }

        protected function checkNetworkAccess(string $url, int $timeout = 3): bool
        {
            return true; // GitHub 可达 → 不切镜像
        }
    };

    $pluginDir = makePluginDir();
    $runner->install($pluginDir, 'cloud-deploy');

    expect($runner->commands)->toHaveCount(1);
    $cmd = $runner->commands[0];
    expect($cmd)->toContain('install --no-dev --no-interaction --optimize-autoloader --no-scripts');
    // 命令在插件 backend 目录内执行
    expect($cmd)->toContain(escapeshellarg("$pluginDir/backend"));
    // composer 前缀原样拼入
    expect($cmd)->toContain("'/usr/bin/php' '/usr/local/bin/composer'");
});

test('runShell 为 composer 子进程提供可写 HOME 和 COMPOSER_HOME', function () {
    $runner = new class(Mockery::mock(BinaryLocator::class), passingPreflight()) extends PluginComposerRunner
    {
        public function exposeRunShell(string $command): array
        {
            return $this->runShell($command);
        }
    };

    $script = <<<'PHP'
echo getenv('HOME')."\n";
echo getenv('COMPOSER_HOME')."\n";
echo getenv('COMPOSER_CACHE_DIR')."\n";
PHP;

    [$exitCode, $output] = $runner->exposeRunShell(
        escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)
    );

    expect($exitCode)->toBe(0);
    expect(explode("\n", trim($output)))->toBe([
        storage_path('app/plugin-composer'),
        storage_path('app/plugin-composer'),
        storage_path('app/plugin-composer/cache'),
    ]);
    expect(is_dir(storage_path('app/plugin-composer/cache')))->toBeTrue();
});

test('install composer install 退出码非 0 时抛明确错误', function () {
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('composer')->andReturn("'php' 'composer'");

    $runner = new class($locator, passingPreflight()) extends PluginComposerRunner
    {
        protected function runShell(string $command): array
        {
            return [1, 'fatal: could not resolve host github.com'];
        }

        protected function checkNetworkAccess(string $url, int $timeout = 3): bool
        {
            return true;
        }
    };

    $pluginDir = makePluginDir();

    expect(fn () => $runner->install($pluginDir, 'cloud-deploy'))
        ->toThrow(RuntimeException::class, '插件 cloud-deploy 依赖安装失败');
});

test('install 执行异常时仍还原 composer 镜像', function () {
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('composer')->andReturn("'php' 'composer'");

    $runner = new class($locator, passingPreflight()) extends PluginComposerRunner
    {
        public bool $reset = false;

        protected function configureComposerMirror(string $basePath, string $composerCmd): bool
        {
            return true;
        }

        protected function resetComposerMirror(string $basePath, string $composerCmd): void
        {
            $this->reset = true;
        }

        protected function runShell(string $command): array
        {
            throw new RuntimeException('process crashed');
        }
    };

    $pluginDir = makePluginDir();

    expect(fn () => $runner->install($pluginDir, 'cloud-deploy'))
        ->toThrow(RuntimeException::class, 'process crashed');
    expect($runner->reset)->toBeTrue();
});

// ==================== install — preflight 失败（composer 不可用 / proc_open 禁用）====================

test('install preflight 报 composer_missing 时抛明确错误且不跑命令', function () {
    $preflight = Mockery::mock(UpgradePreflight::class);
    $preflight->shouldReceive('check')->andReturn([
        'blocking' => [[
            'code' => 'composer_missing',
            'reason' => '未找到 composer phar',
            'fix' => '使用 upgrade.sh 升级',
        ]],
        'items' => [],
        'ini' => ['fpm' => [], 'cli' => []],
    ]);

    $locator = Mockery::mock(BinaryLocator::class);
    // composer() 不应被调用（preflight 先拦下）；若被调用则让测试失败
    $locator->shouldNotReceive('composer');

    $runner = new class($locator, $preflight) extends PluginComposerRunner
    {
        public bool $ran = false;

        protected function runShell(string $command): array
        {
            $this->ran = true;

            return [0, ''];
        }
    };

    $pluginDir = makePluginDir();

    expect(fn () => $runner->install($pluginDir, 'cloud-deploy'))
        ->toThrow(RuntimeException::class, '环境检测未通过');
    expect($runner->ran)->toBeFalse();
});

test('install preflight 报 cli_proc_open_disabled 时抛明确错误', function () {
    $preflight = Mockery::mock(UpgradePreflight::class);
    $preflight->shouldReceive('check')->andReturn([
        'blocking' => [[
            'code' => 'cli_proc_open_disabled',
            'reason' => 'PHP-CLI disable_functions 已禁用 proc_open / exec',
            'fix' => '从 disable_functions 中移除 proc_open / exec',
        ]],
        'items' => [],
        'ini' => ['fpm' => [], 'cli' => []],
    ]);

    $runner = new PluginComposerRunner(Mockery::mock(BinaryLocator::class), $preflight);
    $pluginDir = makePluginDir();

    expect(fn () => $runner->install($pluginDir, 'cloud-deploy'))
        ->toThrow(RuntimeException::class, 'proc_open');
});

test('install preflight 通过但 composer() 仍抛 BinaryNotFound 时给明确文案（防御兜底）', function () {
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('composer')->andThrow(new BinaryNotFoundException('composer', [], []));

    $runner = new PluginComposerRunner($locator, passingPreflight());
    $pluginDir = makePluginDir();

    expect(fn () => $runner->install($pluginDir, 'cloud-deploy'))
        ->toThrow(RuntimeException::class, '未找到可执行的 composer');
});

// ==================== install — 非 composer 阻塞项不拦截 ====================

test('install 仅 FPM proc_open 阻塞（CLI 正常）时不拦截 composer（走 CLI 子进程）', function () {
    // FPM ini 禁了 proc_open 但 CLI 正常：composer install 走 CLI，不应被 FPM 阻塞拦下
    $preflight = Mockery::mock(UpgradePreflight::class);
    $preflight->shouldReceive('check')->andReturn([
        'blocking' => [[
            'code' => 'fpm_proc_open_disabled',
            'reason' => 'FPM 禁用 proc_open',
            'fix' => '移除 FPM disable_functions',
        ]],
        'items' => [],
        'ini' => ['fpm' => [], 'cli' => []],
    ]);

    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('composer')->andReturn("'php' 'composer'");

    $runner = new class($locator, $preflight) extends PluginComposerRunner
    {
        public bool $ran = false;

        protected function runShell(string $command): array
        {
            $this->ran = true;

            return [0, 'ok'];
        }

        protected function checkNetworkAccess(string $url, int $timeout = 3): bool
        {
            return true;
        }
    };

    $pluginDir = makePluginDir();
    $runner->install($pluginDir, 'cloud-deploy'); // 不抛

    expect($runner->ran)->toBeTrue();
});
