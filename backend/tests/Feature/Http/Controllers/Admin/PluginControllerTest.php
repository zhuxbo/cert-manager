<?php

use App\Models\Admin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取已安装插件列表', function () {
    $plugins = [
        ['name' => 'test-plugin', 'version' => '1.0.0'],
    ];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('getInstalledPlugins')->once()->andReturn($plugins);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/plugin/installed');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['plugins']]);
    expect($response->json('data.plugins'))->toBe($plugins);
});

test('管理员可以检查插件更新', function () {
    $updates = [
        ['name' => 'test-plugin', 'latest' => '1.1.0'],
    ];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('checkUpdates')->once()->andReturn($updates);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/plugin/check-updates');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['updates']]);
    expect($response->json('data.updates'))->toBe($updates);
});

test('管理员可以远程安装插件', function () {
    $result = ['name' => 'test-plugin', 'version' => '1.0.0'];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('install')
        ->with('test-plugin', null, null)
        ->once()
        ->andReturn($result);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', [
        'name' => 'test-plugin',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data'))->toBe($result);
});

test('安装插件未指定名称和文件返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', []);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以更新插件', function () {
    $result = ['name' => 'test-plugin', 'version' => '1.1.0'];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('update')
        ->with('test-plugin', null)
        ->once()
        ->andReturn($result);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/update', [
        'name' => 'test-plugin',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data'))->toBe($result);
});

test('更新插件未指定名称返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/update', []);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以卸载插件', function () {
    $result = ['name' => 'test-plugin'];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('uninstall')
        ->with('test-plugin', false)
        ->once()
        ->andReturn($result);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/uninstall', [
        'name' => 'test-plugin',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data'))->toBe($result);
});

test('卸载插件未指定名称返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/uninstall', []);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以卸载插件并删除数据', function () {
    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('uninstall')->with('test-plugin', true)->once()->andReturn(['name' => 'test-plugin']);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/uninstall', [
        'name' => 'test-plugin',
        'remove_data' => true,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以按版本安装插件', function () {
    $result = ['name' => 'test-plugin', 'version' => '2.0.0'];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('install')
        ->with('test-plugin', 'https://example.com/release', '2.0.0')
        ->once()
        ->andReturn($result);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/plugin/install', [
        'name' => 'test-plugin',
        'release_url' => 'https://example.com/release',
        'version' => '2.0.0',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data'))->toBe($result);
});

test('管理员可以上传 ZIP 安装插件', function () {
    $capturedPath = null;
    $result = ['name' => 'zip-plugin', 'version' => '1.0.0'];

    $mock = Mockery::mock(PluginManager::class);
    $mock->shouldReceive('installFromZip')
        ->once()
        ->withArgs(function (string $path) use (&$capturedPath): bool {
            $capturedPath = $path;

            return file_exists($path);
        })
        ->andReturn($result);
    $this->app->instance(PluginManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/plugin/install', [
        'file' => UploadedFile::fake()->create('zip-plugin.zip', 10, 'application/zip'),
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data'))->toBe($result);
    expect($capturedPath)->not->toBeNull();
    expect(file_exists((string) $capturedPath))->toBeFalse();
});

test('上传非 ZIP 文件安装插件返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/plugin/install', [
        'file' => UploadedFile::fake()->create('zip-plugin.txt', 10, 'text/plain'),
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('未认证用户无法访问插件管理', function () {
    $response = $this->getJson('/api/admin/plugin/installed');

    $response->assertUnauthorized();
});
