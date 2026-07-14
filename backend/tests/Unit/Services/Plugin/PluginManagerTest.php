<?php

use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\VersionManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

// ==================== getInstalledPlugins ====================

test('getInstalledPlugins 返回已安装插件列表', function () {
    $versionManager = Mockery::mock(VersionManager::class);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    $pluginDir = "$pluginsPath/test-plugin";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", json_encode([
        'name' => 'test-plugin',
        'version' => '1.2.0',
        'description' => '测试插件',
        'release_url' => 'https://example.com/releases',
        'provider' => 'TestProvider',
    ]));

    $manager = new PluginManager($versionManager);

    // 通过反射设置 pluginsPath
    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    $plugins = $manager->getInstalledPlugins();

    expect($plugins)->toHaveCount(1);
    expect($plugins[0]['name'])->toBe('test-plugin');
    expect($plugins[0]['version'])->toBe('1.2.0');
    expect($plugins[0]['description'])->toBe('测试插件');
    expect($plugins[0]['release_url'])->toBe('https://example.com/releases');

    // 清理
    File::deleteDirectory($pluginsPath);
});

test('getInstalledPlugins 插件目录不存在时返回空数组', function () {
    $versionManager = Mockery::mock(VersionManager::class);

    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, '/tmp/nonexistent_plugins_dir_'.uniqid());

    $plugins = $manager->getInstalledPlugins();

    expect($plugins)->toBe([]);
});

test('getInstalledPlugins 跳过无效的 plugin.json', function () {
    $versionManager = Mockery::mock(VersionManager::class);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    $pluginDir = "$pluginsPath/bad-plugin";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", 'invalid json content');

    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    $plugins = $manager->getInstalledPlugins();

    expect($plugins)->toBe([]);

    File::deleteDirectory($pluginsPath);
});

// ==================== validatePluginName ====================

test('validatePluginName 合法名称通过验证', function (string $name) {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validatePluginName');

    // 不抛出异常即通过
    $method->invoke($manager, $name);
    expect(true)->toBeTrue();
})->with([
    '纯小写字母' => ['myplugin'],
    '字母加数字' => ['plugin2'],
    '带连字符' => ['my-plugin'],
    '复杂合法名' => ['a1-b2-c3'],
]);

test('validatePluginName 非法名称抛出异常', function (string $name) {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validatePluginName');

    $method->invoke($manager, $name);
})->with([
    '大写字母开头' => ['MyPlugin'],
    '数字开头' => ['1plugin'],
    '连字符开头' => ['-plugin'],
    '包含下划线' => ['my_plugin'],
    '包含空格' => ['my plugin'],
    '包含点号' => ['my.plugin'],
    '包含斜杠' => ['../hack'],
    '空字符串' => [''],
])->throws(RuntimeException::class);

// ==================== validateReleaseUrl ====================

test('validateReleaseUrl 合法地址通过验证', function (string $url) {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateReleaseUrl');

    $method->invoke($manager, $url);
    expect(true)->toBeTrue();
})->with([
    'HTTPS 地址' => ['https://example.com/plugin'],
    'HTTP 地址' => ['http://192.168.1.1/plugin'],
    '本地路径' => ['/var/www/plugins/test'],
]);

test('validateReleaseUrl 非法地址抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateReleaseUrl');

    $method->invoke($manager, 'ftp://example.com/plugin');
})->throws(RuntimeException::class, '不安全的更新地址');

// ==================== validateReleaseUrl - SSRF / 传输层收敛（审核 #6） ====================

test('validateReleaseUrl 拒绝公网 http（防中间人替换插件包）', function (string $url) {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateReleaseUrl');

    // 公网 IP 走明文 http 必须拒绝（插件包是可执行代码 → 条件性 RCE）
    expect(fn () => $method->invoke($manager, $url))
        ->toThrow(RuntimeException::class, '明文 HTTP 仅限');
})->with([
    '公网 IP' => ['http://8.8.8.8/plugin/releases.json'],
    '公网域名' => ['http://example.com/plugin'],
]);

test('validateReleaseUrl 放行私网 http（内网离线部署）', function (string $url) {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateReleaseUrl');

    $method->invoke($manager, $url);
    expect(true)->toBeTrue();
})->with([
    '192.168 段' => ['http://192.168.1.10/plugin'],
    '10 段' => ['http://10.0.0.5/plugin'],
    '172.16 段' => ['http://172.16.0.1/plugin'],
    '回环' => ['http://127.0.0.1/plugin'],
]);

test('validateReleaseUrl 拒绝 link-local/元数据/CGNAT/0.0.0.0 http（SSRF 防护）', function (string $url) {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateReleaseUrl');

    // 这些保留段不是合法内网部署目标，http 一律拒绝，防被诱导对元数据/内网发起 SSRF 取回
    expect(fn () => $method->invoke($manager, $url))
        ->toThrow(RuntimeException::class, '明文 HTTP 仅限');
})->with([
    '云元数据 169.254.169.254' => ['http://169.254.169.254/latest/meta-data/'],
    'link-local 169.254' => ['http://169.254.0.1/plugin'],
    'CGNAT 100.64' => ['http://100.64.0.1/plugin'],
    '0.0.0.0' => ['http://0.0.0.0/plugin'],
]);

test('validateReleaseUrl 放行 https（含公网，TLS 防篡改）', function (string $url) {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateReleaseUrl');

    $method->invoke($manager, $url);
    expect(true)->toBeTrue();
})->with([
    '公网 https' => ['https://release.example.com/plugin'],
    '私网 https' => ['https://10.0.0.5/plugin'],
]);

test('validateReleaseUrl 放行本地路径（官方子目录回落）', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validateReleaseUrl');

    $method->invoke($manager, '/var/www/release/plugins/test');
    expect(true)->toBeTrue();
});

// 注：不测"主机无法解析为 IP"分支 —— 不同环境的 DNS 解析器对保留 TLD（.invalid）
// 行为不一致（容器内捕获式解析器会返回地址），该断言会 flaky；防御代码保留。

// ==================== findPluginAssetSha256 / verifyPluginPackageHash（审核 #6） ====================

test('findPluginAssetSha256 提取第一个 zip asset 的 sha256（与 resolveAssetUrl 选同一 asset）', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('findPluginAssetSha256');

    $release = [
        'assets' => [
            ['name' => 'notes.txt', 'sha256' => 'shouldignore'],
            ['name' => 'easy-plugin-1.0.0.zip', 'sha256' => 'abc123def456'],
        ],
    ];

    expect($method->invoke($manager, $release))->toBe('abc123def456');
});

test('findPluginAssetSha256 无 sha256 字段时返回空字符串', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('findPluginAssetSha256');

    $release = ['assets' => [['name' => 'easy-plugin-1.0.0.zip']]];

    expect($method->invoke($manager, $release))->toBe('');
});

test('verifyPluginPackageHash 匹配时通过且保留文件', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $dir = sys_get_temp_dir().'/test_plugin_sha_'.uniqid();
    mkdir($dir, 0755, true);
    $file = "$dir/pkg.zip";
    file_put_contents($file, 'plugin package content');
    $sha = hash('sha256', 'plugin package content');

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('verifyPluginPackageHash');

    $method->invoke($manager, $file, $sha);

    expect(file_exists($file))->toBeTrue();

    File::deleteDirectory($dir);
});

test('verifyPluginPackageHash 大小写无关比对', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $dir = sys_get_temp_dir().'/test_plugin_sha_'.uniqid();
    mkdir($dir, 0755, true);
    $file = "$dir/pkg.zip";
    file_put_contents($file, 'payload');
    $sha = strtoupper(hash('sha256', 'payload'));

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('verifyPluginPackageHash');

    $method->invoke($manager, $file, $sha);

    expect(file_exists($file))->toBeTrue();

    File::deleteDirectory($dir);
});

test('verifyPluginPackageHash 不匹配时抛异常并删除文件（fail-closed）', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $dir = sys_get_temp_dir().'/test_plugin_sha_'.uniqid();
    mkdir($dir, 0755, true);
    $file = "$dir/tampered.zip";
    file_put_contents($file, 'malicious payload');
    $wrongSha = hash('sha256', 'legit content');

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('verifyPluginPackageHash');

    try {
        $method->invoke($manager, $file, $wrongSha);
        test()->fail('应当抛出 RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('sha256 校验不匹配');
        // 被篡改的包必须删除，绝不残留供后续 extract
        expect(file_exists($file))->toBeFalse();
    }

    File::deleteDirectory($dir);
});

test('verifyPluginPackageHash 期望值为空时放行并保留文件（verify-if-present，不阻断存量插件）', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $dir = sys_get_temp_dir().'/test_plugin_sha_'.uniqid();
    mkdir($dir, 0755, true);
    $file = "$dir/unverified.zip";
    file_put_contents($file, 'content');

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('verifyPluginPackageHash');

    // 发布端未提供 sha256：放行（不抛），文件保留供后续解压安装
    $method->invoke($manager, $file, '');
    $method->invoke($manager, $file, "  \n");

    expect(file_exists($file))->toBeTrue();

    File::deleteDirectory($dir);
});

// ==================== 安全检查 - ZIP 路径遍历防护 ====================

test('ZIP 安全检查 - 包含路径遍历的 ZIP 被拒绝', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $downloadPath = sys_get_temp_dir().'/test_extract_'.uniqid();
    mkdir($downloadPath, 0755, true);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('downloadPath');
    $prop->setValue($manager, $downloadPath);

    // 创建一个包含路径遍历的 ZIP 文件
    $zipPath = "$downloadPath/malicious.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('../../../etc/passwd', 'malicious content');
    $zip->close();

    $method = $reflection->getMethod('extractPlugin');

    expect(fn () => $method->invoke($manager, $zipPath))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');

    File::deleteDirectory($downloadPath);
});

test('ZIP 安全检查 - 以斜杠开头的路径被拒绝', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $downloadPath = sys_get_temp_dir().'/test_extract_'.uniqid();
    mkdir($downloadPath, 0755, true);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('downloadPath');
    $prop->setValue($manager, $downloadPath);

    $zipPath = "$downloadPath/absolute_path.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('/etc/shadow', 'malicious content');
    $zip->close();

    $method = $reflection->getMethod('extractPlugin');

    expect(fn () => $method->invoke($manager, $zipPath))
        ->toThrow(RuntimeException::class, 'ZIP 包含非法路径');

    File::deleteDirectory($downloadPath);
});

test('ZIP 安全检查 - 合法 ZIP 可以正常解压', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $downloadPath = sys_get_temp_dir().'/test_extract_'.uniqid();
    mkdir($downloadPath, 0755, true);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('downloadPath');
    $prop->setValue($manager, $downloadPath);

    $zipPath = "$downloadPath/valid.zip";
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('test-plugin/plugin.json', json_encode(['name' => 'test-plugin', 'version' => '1.0.0']));
    $zip->addFromString('test-plugin/README.md', '# Test Plugin');
    $zip->close();

    $method = $reflection->getMethod('extractPlugin');
    $extractDir = $method->invoke($manager, $zipPath);

    expect(is_dir($extractDir))->toBeTrue();
    expect(file_exists("$extractDir/test-plugin/plugin.json"))->toBeTrue();

    File::deleteDirectory($downloadPath);
});

// ==================== 配置解析 ====================

test('配置解析 - plugin.json 缺少 name 字段时使用目录名', function () {
    $versionManager = Mockery::mock(VersionManager::class);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    $pluginDir = "$pluginsPath/fallback-name";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", json_encode([
        'version' => '1.0.0',
        'description' => '没有 name 字段',
    ]));

    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    $plugins = $manager->getInstalledPlugins();

    expect($plugins)->toHaveCount(1);
    expect($plugins[0]['name'])->toBe('fallback-name');
    expect($plugins[0]['version'])->toBe('1.0.0');

    File::deleteDirectory($pluginsPath);
});

// ==================== install ====================

test('install 已安装的插件时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    $pluginDir = "$pluginsPath/existing-plugin";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", json_encode([
        'name' => 'existing-plugin',
        'version' => '1.0.0',
    ]));

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    expect(fn () => $manager->install('existing-plugin'))
        ->toThrow(RuntimeException::class, '插件 existing-plugin 已安装，请使用更新功能');

    File::deleteDirectory($pluginsPath);
});

test('install 无法确定下载地址时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getReleaseUrl')->andReturn(null);

    $manager = new PluginManager($versionManager);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    mkdir($pluginsPath, 0755, true);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    expect(fn () => $manager->install('new-plugin'))
        ->toThrow(RuntimeException::class, '无法确定插件下载地址，请指定 release_url');

    File::deleteDirectory($pluginsPath);
});

// ==================== update ====================

test('update 未安装的插件时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    mkdir($pluginsPath, 0755, true);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    expect(fn () => $manager->update('nonexistent'))
        ->toThrow(RuntimeException::class, '插件 nonexistent 未安装');

    File::deleteDirectory($pluginsPath);
});

// ==================== uninstall ====================

test('rollbackPluginMigrations 重置插件全部迁移批次', function () {
    $manager = new class(Mockery::mock(VersionManager::class)) extends PluginManager
    {
        public array $artisanCalls = [];

        public function rollbackForTest(string $name): void
        {
            $this->rollbackPluginMigrations($name);
        }

        protected function runArtisanProcess(array $arguments, string $context): string
        {
            $this->artisanCalls[] = [$arguments, $context];

            return '';
        }
    };

    $manager->rollbackForTest('cloud-deploy');

    expect($manager->artisanCalls)->toBe([[
        ['migrate:reset', '--path=../plugins/cloud-deploy/backend/migrations', '--force'],
        '插件迁移重置',
    ]]);
});

test('migrate reset 实际清理插件跨批次迁移且保留全局最新批次', function () {
    $suffix = strtolower(substr(str_replace('.', '', uniqid('', true)), -10));
    $pluginName = "test-reset-$suffix";
    $migrationDir = base_path("../plugins/$pluginName/backend/migrations");
    $migrationOne = "2099_01_01_000001_create_pm_reset_a_$suffix";
    $migrationTwo = "2099_01_01_000002_create_pm_reset_b_$suffix";
    $unrelated = "2099_01_01_000003_unrelated_latest_$suffix";
    $tableOne = "pm_reset_a_$suffix";
    $tableTwo = "pm_reset_b_$suffix";

    File::ensureDirectoryExists($migrationDir);
    foreach ([[$migrationOne, $tableOne], [$migrationTwo, $tableTwo]] as [$migration, $table]) {
        File::put("$migrationDir/$migration.php", <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void {}

    public function down(): void
    {
        Schema::dropIfExists('$table');
    }
};
PHP);
        Schema::create($table, fn ($blueprint) => $blueprint->id());
    }
    DB::table('migrations')->insert([
        ['migration' => $migrationOne, 'batch' => 1],
        ['migration' => $migrationTwo, 'batch' => 2],
        ['migration' => $unrelated, 'batch' => 3],
    ]);

    try {
        $exitCode = Artisan::call('migrate:reset', [
            '--path' => "../plugins/$pluginName/backend/migrations",
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(Schema::hasTable($tableOne))->toBeFalse()
            ->and(Schema::hasTable($tableTwo))->toBeFalse()
            ->and(DB::table('migrations')->whereIn('migration', [$migrationOne, $migrationTwo])->count())->toBe(0)
            ->and(DB::table('migrations')->where('migration', $unrelated)->exists())->toBeTrue();
    } finally {
        Schema::dropIfExists($tableOne);
        Schema::dropIfExists($tableTwo);
        DB::table('migrations')
            ->whereIn('migration', [$migrationOne, $migrationTwo, $unrelated])
            ->delete();
        File::deleteDirectory(base_path("../plugins/$pluginName"));
    }
});

test('rollbackPluginMigrations 失败时抛出异常阻止卸载继续', function () {
    $manager = new class(Mockery::mock(VersionManager::class)) extends PluginManager
    {
        public function rollbackForTest(string $name): void
        {
            $this->rollbackPluginMigrations($name);
        }

        protected function runArtisanProcess(array $arguments, string $context): string
        {
            throw new RuntimeException('reset failed');
        }
    };

    expect(fn () => $manager->rollbackForTest('cloud-deploy'))
        ->toThrow(RuntimeException::class, '插件 cloud-deploy 迁移重置失败：reset failed');
});

test('cleanupPluginSeeders 失败时抛出异常阻止卸载误报数据已清除', function () {
    $seeder = new class
    {
        public function clear(): void
        {
            throw new RuntimeException('clear failed');
        }
    };
    $seederClass = $seeder::class;
    app()->instance($seederClass, $seeder);

    $manager = new class(Mockery::mock(VersionManager::class), $seederClass) extends PluginManager
    {
        public function __construct(VersionManager $versionManager, private readonly string $seederClass)
        {
            parent::__construct($versionManager);
        }

        public function cleanupSeedersForTest(string $name): void
        {
            $this->cleanupPluginSeeders($name);
        }

        protected function loadPluginSeederClass(string $name): ?string
        {
            return $this->seederClass;
        }
    };

    expect(fn () => $manager->cleanupSeedersForTest('cloud-deploy'))
        ->toThrow(RuntimeException::class, '插件 cloud-deploy Seed 清理失败：clear failed');
});

test('完全清除失败时保留插件目录以便重试', function () {
    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    $pluginDir = "$pluginsPath/test-plugin";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", json_encode(['name' => 'test-plugin']));

    $manager = new class(Mockery::mock(VersionManager::class)) extends PluginManager
    {
        protected function rollbackPluginMigrations(string $name): void
        {
            throw new RuntimeException('reset failed');
        }
    };
    $reflection = new ReflectionClass($manager);
    $reflection->getProperty('pluginsPath')->setValue($manager, $pluginsPath);

    expect(fn () => $manager->uninstall('test-plugin', true))
        ->toThrow(RuntimeException::class, 'reset failed')
        ->and(is_dir($pluginDir))->toBeTrue();

    File::deleteDirectory($pluginsPath);
});

test('uninstall 不存在的插件时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    mkdir($pluginsPath, 0755, true);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    expect(fn () => $manager->uninstall('nonexistent'))
        ->toThrow(RuntimeException::class, '插件 nonexistent 不存在');

    File::deleteDirectory($pluginsPath);
});

// ==================== validatePluginPath ====================

test('安全检查 - 路径遍历防护 validatePluginPath', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    mkdir($pluginsPath, 0755, true);

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    $method = $reflection->getMethod('validatePluginPath');

    // 尝试访问 plugins 目录之外的路径
    expect(fn () => $method->invoke($manager, '/tmp'))
        ->toThrow(RuntimeException::class);

    File::deleteDirectory($pluginsPath);
});

// ==================== findLatestRelease ====================

test('findLatestRelease 返回最新版本', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('findLatestRelease');

    $releases = [
        ['tag_name' => 'v1.0.0'],
        ['tag_name' => 'v2.0.0'],
        ['tag_name' => 'v1.5.0'],
    ];

    $result = $method->invoke($manager, $releases);

    expect($result['version'])->toBe('2.0.0');
    expect($result['tag_name'])->toBe('v2.0.0');
});

test('findLatestRelease 无有效版本时返回 null', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('findLatestRelease');

    $result = $method->invoke($manager, []);

    expect($result)->toBeNull();
});

// ==================== findReleaseByVersion ====================

test('findReleaseByVersion 找到匹配版本', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('findReleaseByVersion');

    $releases = [
        ['tag_name' => 'v1.0.0', 'body' => 'Release 1'],
        ['tag_name' => 'v2.0.0', 'body' => 'Release 2'],
    ];

    $result = $method->invoke($manager, $releases, '1.0.0');

    expect($result['version'])->toBe('1.0.0');
    expect($result['body'])->toBe('Release 1');
});

test('findReleaseByVersion 支持 v 前缀', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('findReleaseByVersion');

    $releases = [
        ['tag_name' => 'v1.0.0'],
    ];

    $result = $method->invoke($manager, $releases, 'v1.0.0');

    expect($result['version'])->toBe('1.0.0');
});

test('findReleaseByVersion 未找到版本时返回 null', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('findReleaseByVersion');

    $releases = [
        ['tag_name' => 'v1.0.0'],
    ];

    $result = $method->invoke($manager, $releases, '2.0.0');

    expect($result)->toBeNull();
});

// ==================== checkCompatibility ====================

test('checkCompatibility 兼容时不抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getVersionString')->andReturn('2.0.0');

    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('checkCompatibility');

    // 不抛出异常即通过
    $method->invoke($manager, ['requires' => '>=1.0.0']);
    expect(true)->toBeTrue();
});

test('checkCompatibility 不兼容时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getVersionString')->andReturn('1.0.0');

    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('checkCompatibility');

    expect(fn () => $method->invoke($manager, ['requires' => '>=2.0.0']))
        ->toThrow(RuntimeException::class, '插件要求系统版本 >=2.0.0，当前版本 v1.0.0');
});

test('checkCompatibility 无 requires 字段时通过', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('checkCompatibility');

    $method->invoke($manager, []);
    expect(true)->toBeTrue();
});

// ==================== resolveAssetUrl ====================

test('resolveAssetUrl 从 assets 中获取 ZIP 下载地址', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveAssetUrl');

    $release = [
        'version' => '1.0.0',
        'tag_name' => 'v1.0.0',
        'assets' => [
            [
                'name' => 'plugin-1.0.0.zip',
                'browser_download_url' => 'https://cdn.example.com/plugin-1.0.0.zip',
            ],
        ],
    ];

    $result = $method->invoke($manager, $release, 'https://example.com/releases');

    expect($result)->toBe('https://cdn.example.com/plugin-1.0.0.zip');
});

test('resolveAssetUrl 相对路径转换为完整 URL', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveAssetUrl');

    $release = [
        'version' => '1.0.0',
        'tag_name' => 'v1.0.0',
        'assets' => [
            [
                'name' => 'plugin-1.0.0.zip',
                'browser_download_url' => 'v1.0.0/plugin-1.0.0.zip',
            ],
        ],
    ];

    $result = $method->invoke($manager, $release, 'https://example.com/releases');

    expect($result)->toBe('https://example.com/releases/v1.0.0/plugin-1.0.0.zip');
});

test('resolveAssetUrl 无 assets 时构造默认 URL', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('resolveAssetUrl');

    $release = [
        'version' => '1.0.0',
        'tag_name' => 'v1.0.0',
        'assets' => [],
    ];

    $result = $method->invoke($manager, $release, 'https://example.com/releases/my-plugin');

    expect($result)->toBe('https://example.com/releases/my-plugin/v1.0.0/my-plugin-plugin-1.0.0.zip');
});

// ==================== validatePlugin ====================

test('validatePlugin 缺少 plugin.json 时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $tempDir = sys_get_temp_dir().'/test_validate_'.uniqid();
    mkdir($tempDir, 0755, true);

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validatePlugin');

    expect(fn () => $method->invoke($manager, $tempDir))
        ->toThrow(RuntimeException::class, '插件包无效：缺少 plugin.json');

    File::deleteDirectory($tempDir);
});

test('validatePlugin plugin.json 格式错误时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $tempDir = sys_get_temp_dir().'/test_validate_'.uniqid();
    mkdir($tempDir, 0755, true);
    file_put_contents("$tempDir/plugin.json", 'not json');

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validatePlugin');

    expect(fn () => $method->invoke($manager, $tempDir))
        ->toThrow(RuntimeException::class, 'plugin.json 格式错误');

    File::deleteDirectory($tempDir);
});

test('validatePlugin 缺少 name 字段时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $tempDir = sys_get_temp_dir().'/test_validate_'.uniqid();
    mkdir($tempDir, 0755, true);
    file_put_contents("$tempDir/plugin.json", json_encode(['version' => '1.0.0']));

    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('validatePlugin');

    expect(fn () => $method->invoke($manager, $tempDir))
        ->toThrow(RuntimeException::class, 'plugin.json 缺少 name 字段');

    File::deleteDirectory($tempDir);
});

test('validatePlugin 名称不匹配时抛出异常', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $downloadPath = sys_get_temp_dir().'/test_download_'.uniqid();
    mkdir($downloadPath, 0755, true);

    $tempDir = "$downloadPath/extract_test";
    mkdir($tempDir, 0755, true);
    file_put_contents("$tempDir/plugin.json", json_encode(['name' => 'wrong-name']));

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('downloadPath');
    $prop->setValue($manager, $downloadPath);

    $method = $reflection->getMethod('validatePlugin');

    expect(fn () => $method->invoke($manager, $tempDir, 'expected-name'))
        ->toThrow(RuntimeException::class, '插件名不匹配：期望 expected-name，实际 wrong-name');

    File::deleteDirectory($downloadPath);
});

// ==================== getPluginReleaseUrl ====================

test('getPluginReleaseUrl 从 plugin.json 读取 release_url', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $manager = new PluginManager($versionManager);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    $pluginDir = "$pluginsPath/my-plugin";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", json_encode([
        'name' => 'my-plugin',
        'version' => '1.0.0',
        'release_url' => 'https://custom.example.com/my-plugin/',
    ]));

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    $result = $manager->getPluginReleaseUrl('my-plugin');

    expect($result)->toBe('https://custom.example.com/my-plugin');

    File::deleteDirectory($pluginsPath);
});

test('getPluginReleaseUrl 无 release_url 时回退到系统地址', function () {
    $versionManager = Mockery::mock(VersionManager::class);
    $versionManager->shouldReceive('getReleaseUrl')->andReturn('https://releases.example.com');

    $manager = new PluginManager($versionManager);

    $pluginsPath = sys_get_temp_dir().'/test_plugins_'.uniqid();
    $pluginDir = "$pluginsPath/my-plugin";
    mkdir($pluginDir, 0755, true);
    file_put_contents("$pluginDir/plugin.json", json_encode([
        'name' => 'my-plugin',
        'version' => '1.0.0',
    ]));

    $reflection = new ReflectionClass($manager);
    $prop = $reflection->getProperty('pluginsPath');
    $prop->setValue($manager, $pluginsPath);

    $result = $manager->getPluginReleaseUrl('my-plugin');

    expect($result)->toBe('https://releases.example.com/plugins/my-plugin');

    File::deleteDirectory($pluginsPath);
});
