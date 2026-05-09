<?php

use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\UserLog;
use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\VersionManager;
use App\Utils\UpgradeFreezeLock;

uses()->group('database');

beforeEach(function () {
    UpgradeFreezeLock::unfreeze();
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
// 7. 不写日志（与 /api/health 同级）
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
