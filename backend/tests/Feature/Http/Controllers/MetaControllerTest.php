<?php

use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\UserLog;
use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\VersionManager;
use App\Utils\UpgradeFreezeLock;

uses()->group('database');

beforeEach(function () {
    UpgradeFreezeLock::unfreeze();
    Setting::clearAllCache();
    setPlatformSettings('site', [
        'dnsTools' => ['type' => 'array', 'value' => [
            'https://dns-tools-cn.cnssl.com',
            'https://dns-tools-us.cnssl.com',
        ]],
    ]);
    setPlatformSettings('brand', [
        'all' => ['type' => 'array', 'value' => ['digicert' => 'DigiCert', 'certum' => 'Certum']],
        'admin' => ['type' => 'array', 'value' => ['digicert']],
        'user' => ['type' => 'array', 'value' => ['certum']],
    ]);
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze();
});

/**
 * 用 anonymous subclass 替换 PluginManager::getInstalledPlugins() 返回值，
 * 避免依赖仓库实际的 plugins/ 目录（不稳定）。
 *
 * @param  list<array<string, mixed>>  $list
 */
function bindFakePluginManager(array $list): void
{
    app()->bind(PluginManager::class, function () use ($list) {
        return new class($list) extends PluginManager
        {
            public function __construct(private array $list)
            {
                parent::__construct(app(VersionManager::class));
            }

            public function getInstalledPlugins(): array
            {
                return $this->list;
            }
        };
    });
}

/** @param array<string, array{type: string, value: mixed, is_multiple?: bool}> $values */
function setPlatformSettings(string $groupName, array $values): void
{
    $group = SettingGroup::firstOrCreate(
        ['name' => $groupName],
        ['title' => $groupName, 'weight' => 99],
    );

    foreach ($values as $key => $attributes) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            [
                'type' => $attributes['type'],
                'is_multiple' => $attributes['is_multiple'] ?? false,
                'value' => $attributes['value'],
                'weight' => 1,
            ],
        );
    }
}

// ==========================================
// 1. 匿名访问
// ==========================================

test('GET /api/meta 不带任何鉴权 header 仍可访问 200', function () {
    bindFakePluginManager([]);

    // 无 Authorization / 无 cookie / 无 session
    $response = $this->get('/api/meta');

    $response->assertOk();
    $response->assertJsonStructure([
        'code',
        'data' => [
            'channels' => ['admin', 'user', 'api', 'deploy'],
            'plugins',
            'version',
        ],
    ]);
    expect($response->json('code'))->toBe(1);
});

// ==========================================
// 2. 默认 channels 全 true
// ==========================================

test('默认配置返回 4 个 channel 全 true', function () {
    bindFakePluginManager([]);

    $response = $this->getJson('/api/meta');

    $response->assertOk();
    $response->assertJson([
        'code' => 1,
        'data' => [
            'channels' => [
                'admin' => true,
                'user' => true,
                'api' => true,
                'deploy' => true,
            ],
        ],
    ]);
});

// ==========================================
// 3. config 覆盖反映在 meta 响应中
// ==========================================

test('config(channels.admin=false) 反映到响应', function () {
    bindFakePluginManager([]);
    config(['channels.admin' => false]);

    $response = $this->getJson('/api/meta');

    $response->assertOk();
    expect($response->json('data.channels.admin'))->toBeFalse();
    expect($response->json('data.channels.user'))->toBeTrue();
    expect($response->json('data.channels.api'))->toBeTrue();
    expect($response->json('data.channels.deploy'))->toBeTrue();
});

test('多个 channel 覆盖独立生效', function () {
    bindFakePluginManager([]);
    config([
        'channels.admin' => false,
        'channels.user' => false,
        'channels.api' => true,
        'channels.deploy' => false,
    ]);

    $response = $this->getJson('/api/meta');

    $response->assertOk();
    $response->assertJson([
        'data' => [
            'channels' => [
                'admin' => false,
                'user' => false,
                'api' => true,
                'deploy' => false,
            ],
        ],
    ]);
});

// ==========================================
// 4. freeze 期间仍可访问（白名单）
// ==========================================

test('freeze 期间 /api/meta 仍 200 不被拦截', function () {
    UpgradeFreezeLock::freeze();
    bindFakePluginManager([]);

    $response = $this->getJson('/api/meta');

    $response->assertOk();
    expect($response->json('code'))->toBe(1);
    expect($response->json('data.channels.admin'))->toBeTrue();
});

// ==========================================
// 5. plugins 列表 + 字段精简
// ==========================================

test('plugins 字段返回 name 和 version 二元组', function () {
    bindFakePluginManager([
        ['name' => 'invoice', 'version' => '1.2.3', 'description' => 'inv', 'release_url' => 'https://x', 'provider' => 'p1'],
        ['name' => 'easy', 'version' => '0.5.0', 'description' => '', 'release_url' => '', 'provider' => ''],
    ]);

    $response = $this->getJson('/api/meta');

    $response->assertOk();
    $plugins = $response->json('data.plugins');
    expect($plugins)->toBeArray()->toHaveCount(2);
    expect($plugins[0])->toMatchArray(['name' => 'invoice', 'version' => '1.2.3']);
    expect($plugins[1])->toMatchArray(['name' => 'easy', 'version' => '0.5.0']);
    // 不暴露 release_url / provider / description 等敏感/冗余字段
    expect($plugins[0])->not->toHaveKey('release_url');
    expect($plugins[0])->not->toHaveKey('provider');
    expect($plugins[0])->not->toHaveKey('description');
});

test('插件列表为空时 plugins 是空数组（不是 null）', function () {
    bindFakePluginManager([]);

    $response = $this->getJson('/api/meta');

    $response->assertOk();
    expect($response->json('data.plugins'))->toBeArray()->toBeEmpty();
});

// ==========================================
// 6. version 字段
// ==========================================

test('version 字段来自 config(version.version)', function () {
    bindFakePluginManager([]);
    config(['version.version' => '9.9.9-test']);

    $response = $this->getJson('/api/meta');

    $response->assertOk();
    expect($response->json('data.version'))->toBe('9.9.9-test');
});

// ==========================================
// 7. 前端平台设置
// ==========================================

test('user 平台设置复用 site 并读取独立品牌', function () {
    bindFakePluginManager([]);
    setPlatformSettings('site', [
        'name' => ['type' => 'string', 'value' => '证书中心'],
        'dnsTools' => ['type' => 'array', 'value' => ['cn' => 'https://dns-cn.test', 'us' => 'https://dns-us.test']],
        'beian' => ['type' => 'string', 'value' => '京ICP备123号'],
        'copyStart' => ['type' => 'integer', 'value' => 2020],
        'favicon' => ['type' => 'image', 'value' => '/api/meta/site-image/favicon-abc.ico'],
        'logo' => ['type' => 'image', 'value' => '/storage/site/logo-abc.png'],
        'logoExpanded' => ['type' => 'image', 'value' => '/storage/site/logo-expanded-abc.png'],
        'qrcode' => ['type' => 'image', 'value' => '/storage/site/qrcode-def.png'],
    ]);
    setPlatformSettings('brand', [
        'all' => ['type' => 'array', 'value' => [
            'certum' => 'Certum',
            'digicert' => 'DigiCert',
            'sectigo' => 'Sectigo',
        ]],
        'admin' => ['type' => 'array', 'value' => ['digicert']],
        'user' => ['type' => 'array', 'value' => ['sectigo', 'certum']],
    ]);

    $response = $this->getJson('/api/meta?channel=user');

    $response->assertOk()->assertJsonPath('data.platform', [
        'Title' => '证书中心',
        'AllBrands' => [
            ['label' => 'Certum', 'value' => 'certum'],
            ['label' => 'DigiCert', 'value' => 'digicert'],
            ['label' => 'Sectigo', 'value' => 'sectigo'],
        ],
        'Brands' => [
            ['label' => 'Sectigo', 'value' => 'sectigo'],
            ['label' => 'Certum', 'value' => 'certum'],
        ],
        'DnsTools' => ['https://dns-cn.test', 'https://dns-us.test'],
        'Beian' => '京ICP备123号',
        'CopyStart' => '2020',
        'Favicon' => '/api/meta/site-image/favicon-abc.ico',
        'Logo' => '/storage/site/logo-abc.png',
        'LogoExpanded' => '/storage/site/logo-expanded-abc.png',
        'Qrcode' => '/storage/site/qrcode-def.png',
    ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('admin 平台设置只切换品牌且共享站点设置', function () {
    bindFakePluginManager([]);
    setPlatformSettings('site', [
        'name' => ['type' => 'string', 'value' => '统一标题'],
    ]);
    setPlatformSettings('brand', [
        'all' => ['type' => 'array', 'value' => ['digicert' => 'DigiCert', 'certum' => 'Certum']],
        'admin' => ['type' => 'array', 'value' => ['DIGICERT']],
        'user' => ['type' => 'array', 'value' => ['certum']],
    ]);

    $response = $this->getJson('/api/meta?channel=admin');

    $response->assertOk()
        ->assertJsonPath('data.platform.Title', '统一标题')
        ->assertJsonPath('data.platform.Brands', [['label' => 'DigiCert', 'value' => 'digicert']]);
});

test('活动品牌按渠道设置排序并过滤重复和词典外品牌', function () {
    bindFakePluginManager([]);
    setPlatformSettings('brand', [
        'all' => ['type' => 'array', 'value' => [
            'certum' => 'Certum',
            'digicert' => 'DigiCert',
            'ssltrus' => '锐安信',
        ]],
        'admin' => ['type' => 'array', 'value' => [' ssltrus ', 'CERTUM', 'ssltrus', 'unknown']],
    ]);

    $response = $this->getJson('/api/meta?channel=admin');

    $response->assertOk()->assertJsonPath('data.platform.Brands', [
        ['label' => '锐安信', 'value' => 'ssltrus'],
        ['label' => 'Certum', 'value' => 'certum'],
    ]);
});

test('user 活动品牌顺序独立于全部品牌和 admin', function () {
    bindFakePluginManager([]);
    setPlatformSettings('brand', [
        'all' => ['type' => 'array', 'value' => ['certum' => 'Certum', 'digicert' => 'DigiCert']],
        'admin' => ['type' => 'array', 'value' => ['certum', 'digicert']],
        'user' => ['type' => 'array', 'value' => ['digicert', 'certum']],
    ]);

    $response = $this->getJson('/api/meta?channel=user');

    $response->assertOk()->assertJsonPath('data.platform.Brands', [
        ['label' => 'DigiCert', 'value' => 'digicert'],
        ['label' => 'Certum', 'value' => 'certum'],
    ]);
});

test('平台设置缺失时返回与现有静态配置一致的默认值', function () {
    expectsBreakingChange('platform-config-2026-07: site.dnsTools 缺失时移除程序硬编码回落并返回空数组');

    bindFakePluginManager([]);
    SettingGroup::whereIn('name', ['site', 'brand'])->each(function (SettingGroup $group) {
        $group->settings()->delete();
        Setting::clearGroupCache($group->id);
    });

    $response = $this->getJson('/api/meta?channel=user');

    $response->assertOk()
        ->assertJsonPath('data.platform.Title', 'SSL')
        ->assertJsonPath('data.platform.CopyStart', '2017')
        ->assertJsonPath('data.platform.Favicon', '')
        ->assertJsonPath('data.platform.Logo', '/logo.svg')
        ->assertJsonPath('data.platform.LogoExpanded', '')
        ->assertJsonPath('data.platform.Qrcode', '/qrcode.svg');
    expect($response->json('data.platform.AllBrands'))->toBeArray()->toBeEmpty()
        ->and($response->json('data.platform.Brands'))->toBeArray()->toBeEmpty()
        ->and($response->json('data.platform.DnsTools'))->toBeArray()->toBeEmpty();
});

// ==========================================
// 8. 不写日志（与 /api/health 同级）
// ==========================================

test('GET /api/meta 不写任何业务日志', function () {
    bindFakePluginManager([]);

    expect(AdminLog::count())->toBe(0);
    expect(ApiLog::count())->toBe(0);
    expect(UserLog::count())->toBe(0);
    expect(CallbackLog::count())->toBe(0);

    $this->getJson('/api/meta')->assertOk();

    expect(AdminLog::count())->toBe(0);
    expect(ApiLog::count())->toBe(0);
    expect(UserLog::count())->toBe(0);
    expect(CallbackLog::count())->toBe(0);
});

test('channel 参数为数组等非字符串形态时回落 user 端而非报错', function () {
    bindFakePluginManager([]);
    setPlatformSettings('brand', [
        'all' => ['type' => 'array', 'value' => ['digicert' => 'DigiCert', 'certum' => 'Certum']],
        'admin' => ['type' => 'array', 'value' => ['digicert']],
        'user' => ['type' => 'array', 'value' => ['certum']],
    ]);

    $response = $this->getJson('/api/meta?channel[]=admin');

    $response->assertOk()->assertJsonPath('data.platform.Brands', [
        ['label' => 'Certum', 'value' => 'certum'],
    ]);
});
