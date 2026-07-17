<?php

use App\Models\Admin;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Upgrade\UpgradeService;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Services\Upgrade\VersionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取当前版本信息', function () {
    $mock = Mockery::mock(VersionManager::class);
    $mock->shouldReceive('getCurrentVersion')->once()->andReturn([
        'version' => '1.0.0',
        'channel' => 'main',
    ]);
    $this->app->instance(VersionManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/upgrade/version');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.version', '1.0.0');
    $response->assertJsonPath('data.channel', 'main');
});

test('管理员可以检查系统更新', function () {
    $mock = Mockery::mock(UpgradeService::class);
    $mock->shouldReceive('checkForUpdate')->once()->andReturn([
        'has_update' => false,
        'current_version' => '1.0.0',
    ]);
    $this->app->instance(UpgradeService::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/upgrade/check');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.has_update', false);
    $response->assertJsonPath('data.current_version', '1.0.0');
});

test('管理员可以获取历史版本列表', function () {
    $releaseHistory = [
        ['tag_name' => 'v1.0.1'],
        ['tag_name' => 'v1.0.0'],
    ];

    $mockUpgrade = Mockery::mock(UpgradeService::class);
    $mockUpgrade->shouldReceive('getReleaseHistory')->with(5)->once()->andReturn($releaseHistory);
    $this->app->instance(UpgradeService::class, $mockUpgrade);

    $mockVersion = Mockery::mock(VersionManager::class);
    $mockVersion->shouldReceive('getVersionString')->once()->andReturn('1.0.0');
    $this->app->instance(VersionManager::class, $mockVersion);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/upgrade/releases');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['releases', 'current_version']]);
    expect($response->json('data.releases'))->toBe($releaseHistory);
    $response->assertJsonPath('data.current_version', '1.0.0');
});

test('releases-显式 null limit 归一为默认 int 5（input 默认值对显式 null 不生效）', function () {
    $mockUpgrade = Mockery::mock(UpgradeService::class);
    $mockUpgrade->shouldReceive('getReleaseHistory')->with(5)->once()->andReturn([]);
    $this->app->instance(UpgradeService::class, $mockUpgrade);

    $mockVersion = Mockery::mock(VersionManager::class);
    $mockVersion->shouldReceive('getVersionString')->andReturn('1.0.0');
    $this->app->instance(VersionManager::class, $mockVersion);

    $this->actingAsAdmin($this->admin)
        ->json('GET', '/api/admin/upgrade/releases', ['limit' => null])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('管理员可以获取升级状态', function () {
    $mock = Mockery::mock(UpgradeStatusManager::class);
    $mock->shouldReceive('get')->once()->andReturn(null);
    $this->app->instance(UpgradeStatusManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/upgrade/status');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.status', 'idle');
});

test('管理员可以获取升级进行中的状态', function () {
    $mock = Mockery::mock(UpgradeStatusManager::class);
    $mock->shouldReceive('get')->once()->andReturn([
        'status' => 'running',
        'step' => 'downloading',
        'progress' => 50,
    ]);
    $this->app->instance(UpgradeStatusManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/upgrade/status');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.status', 'running');
    $response->assertJsonPath('data.step', 'downloading');
    $response->assertJsonPath('data.progress', 50);
});

test('管理员可以获取备份列表', function () {
    $backups = [
        ['id' => 'backup_20250101', 'size' => 1024],
    ];

    $mock = Mockery::mock(UpgradeService::class);
    $mock->shouldReceive('getBackups')->once()->andReturn($backups);
    $this->app->instance(UpgradeService::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/upgrade/backups');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['backups']]);
    expect($response->json('data.backups'))->toBe($backups);
});

test('管理员可以执行回滚', function () {
    $mock = Mockery::mock(UpgradeService::class);
    $mock->shouldReceive('rollback')->with('backup_20250101')->once()->andReturn(['success' => true]);
    $this->app->instance(UpgradeService::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/rollback', [
        'backup_id' => 'backup_20250101',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.success', true);
});

test('回滚未指定备份ID返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/rollback', []);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('回滚失败时返回错误', function () {
    $mock = Mockery::mock(UpgradeService::class);
    $mock->shouldReceive('rollback')->with('backup_20250101')->once()->andReturn([
        'success' => false,
        'error' => 'backup broken',
    ]);
    $this->app->instance(UpgradeService::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/rollback', [
        'backup_id' => 'backup_20250101',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    $response->assertJsonPath('msg', 'backup broken');
});

test('管理员可以删除备份', function () {
    $mock = Mockery::mock(UpgradeService::class);
    $mock->shouldReceive('deleteBackup')->with('backup_20250101')->once()->andReturn(true);
    $this->app->instance(UpgradeService::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/upgrade/backup?backup_id=backup_20250101');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.deleted', true);
});

test('删除备份失败返回错误', function () {
    $mock = Mockery::mock(UpgradeService::class);
    $mock->shouldReceive('deleteBackup')->with('backup_20250101')->once()->andReturn(false);
    $this->app->instance(UpgradeService::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/upgrade/backup?backup_id=backup_20250101');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以设置发布通道', function () {
    $mock = Mockery::mock(VersionManager::class);
    $mock->shouldReceive('setChannel')->with('dev')->once()->andReturn(true);
    $this->app->instance(VersionManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/channel', [
        'channel' => 'dev',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.channel', 'dev');
});

test('设置无效通道返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/channel', [
        'channel' => 'invalid',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('设置通道失败返回错误', function () {
    $mock = Mockery::mock(VersionManager::class);
    $mock->shouldReceive('setChannel')->with('main')->once()->andReturn(false);
    $this->app->instance(VersionManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/channel', [
        'channel' => 'main',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
});

test('已有升级任务运行时不能启动新任务', function () {
    $mock = Mockery::mock(UpgradeStatusManager::class);
    $mock->shouldReceive('isRunning')->once()->andReturn(true);
    $this->app->instance(UpgradeStatusManager::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/execute', [
        'version' => 'latest',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    $response->assertJsonPath('msg', '已有升级任务在运行中');
});

test('未认证用户无法访问升级管理', function () {
    $response = $this->getJson('/api/admin/upgrade/version');

    $response->assertUnauthorized();
});

test('POST upgrade/execute 在 FPM disable_functions 禁了 proc_open 时返回 503', function () {
    $statusMock = Mockery::mock(UpgradeStatusManager::class);
    $statusMock->shouldReceive('isRunning')->once()->andReturn(false);
    $this->app->instance(UpgradeStatusManager::class, $statusMock);

    $locatorMock = Mockery::mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn([
        'disable_functions_ok' => false,
        'disable_functions' => 'proc_open',
        'ini_path' => '/etc/php.ini',
    ]);
    $locatorMock->shouldReceive('php')->andReturn('/usr/bin/php');
    $locatorMock->shouldReceive('composer')->andReturn('/usr/bin/php /usr/local/bin/composer');
    $locatorMock->shouldReceive('inspectCliIni')->andReturn([
        'disable_functions_ok' => true,
        'ini_path' => '/etc/php-cli.ini',
    ]);
    $this->app->instance(BinaryLocator::class, $locatorMock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/execute', [
        'version' => 'latest',
    ]);

    $response->assertStatus(503);
    $codes = collect($response->json('data.blocking'))->pluck('code')->all();
    expect($codes)->toContain('fpm_proc_open_disabled');
});

test('POST upgrade/execute 在 PHP CLI 找不到时返回 503', function () {
    $statusMock = Mockery::mock(UpgradeStatusManager::class);
    $statusMock->shouldReceive('isRunning')->once()->andReturn(false);
    $this->app->instance(UpgradeStatusManager::class, $statusMock);

    $locatorMock = Mockery::mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn([
        'disable_functions_ok' => true,
        'ini_path' => '/etc/php.ini',
    ]);
    $locatorMock->shouldReceive('php')->andThrow(new BinaryNotFoundException(
        tool: 'php',
        triedPaths: ['/usr/bin/php'],
        diagnose: ['php cli missing'],
    ));
    $locatorMock->shouldReceive('composer')->andReturn('/usr/bin/php /usr/local/bin/composer');
    $this->app->instance(BinaryLocator::class, $locatorMock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/execute', [
        'version' => 'latest',
    ]);

    $response->assertStatus(503);
    $codes = collect($response->json('data.blocking'))->pluck('code')->all();
    expect($codes)->toContain('php_cli_missing');
});

test('POST upgrade/execute 在 composer 找不到时返回 503', function () {
    $statusMock = Mockery::mock(UpgradeStatusManager::class);
    $statusMock->shouldReceive('isRunning')->once()->andReturn(false);
    $this->app->instance(UpgradeStatusManager::class, $statusMock);

    $locatorMock = Mockery::mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn([
        'disable_functions_ok' => true,
        'ini_path' => '/etc/php.ini',
    ]);
    $locatorMock->shouldReceive('inspectCliIni')->andReturn([
        'disable_functions_ok' => true,
        'ini_path' => '/etc/php-cli.ini',
    ]);
    $locatorMock->shouldReceive('php')->andReturn('/usr/bin/php');
    $locatorMock->shouldReceive('composer')->andThrow(new BinaryNotFoundException(
        tool: 'composer',
        triedPaths: [],
        diagnose: [],
    ));
    $this->app->instance(BinaryLocator::class, $locatorMock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/execute', [
        'version' => 'latest',
    ]);

    $response->assertStatus(503);
    $codes = collect($response->json('data.blocking'))->pluck('code')->all();
    expect($codes)->toContain('composer_missing');
});

test('POST upgrade/execute 在 CLI disable_functions 禁了 proc_open 时返回 503', function () {
    $statusMock = Mockery::mock(UpgradeStatusManager::class);
    $statusMock->shouldReceive('isRunning')->once()->andReturn(false);
    $this->app->instance(UpgradeStatusManager::class, $statusMock);

    $locatorMock = Mockery::mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn([
        'disable_functions_ok' => true,
        'ini_path' => '/etc/php.ini',
    ]);
    $locatorMock->shouldReceive('inspectCliIni')->andReturn([
        'disable_functions_ok' => false,
        'disable_functions' => 'proc_open',
        'ini_path' => '/www/server/php/84/etc/php-cli.ini',
    ]);
    $locatorMock->shouldReceive('php')->andReturn('/usr/bin/php');
    $locatorMock->shouldReceive('composer')->andReturn('/usr/bin/php /usr/local/bin/composer');
    $this->app->instance(BinaryLocator::class, $locatorMock);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/upgrade/execute', [
        'version' => 'latest',
    ]);

    $response->assertStatus(503);
    $codes = collect($response->json('data.blocking'))->pluck('code')->all();
    expect($codes)->toContain('cli_proc_open_disabled');
});

test('GET upgrade/binary-health 返回所有工具状态 + ini 两段', function () {
    $locatorMock = Mockery::mock(BinaryLocator::class);
    $locatorMock->shouldReceive('php')->andReturn('/usr/bin/php');
    $locatorMock->shouldReceive('composer')->andReturn('/usr/bin/php /usr/local/bin/composer');
    $locatorMock->shouldReceive('openssl')->andReturn('/usr/bin/openssl');
    $locatorMock->shouldReceive('java')->andThrow(
        new BinaryNotFoundException(tool: 'java', triedPaths: [], diagnose: ['no java'])
    );
    $locatorMock->shouldReceive('keytool')->andThrow(
        new BinaryNotFoundException(tool: 'keytool', triedPaths: [], diagnose: [])
    );
    $locatorMock->shouldReceive('mysqldump')->andReturn('/usr/bin/mysqldump');
    $locatorMock->shouldReceive('mysql')->andReturn('/usr/bin/mysql');
    $locatorMock->shouldReceive('curl')->andReturn('/usr/bin/curl');
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn(['disable_functions_ok' => true, 'ini_path' => '/etc/php.ini']);
    $locatorMock->shouldReceive('inspectCliIni')->andReturn(['disable_functions_ok' => true, 'ini_path' => '/etc/php-cli.ini']);
    $this->app->instance(BinaryLocator::class, $locatorMock);

    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/upgrade/binary-health')
        ->assertOk();

    $items = collect($response->json('data.items'));
    expect($items)->toHaveCount(8);

    expect($items->firstWhere('tool', 'php'))->toMatchArray(['tool' => 'php', 'status' => 'ok', 'path' => '/usr/bin/php']);
    expect($items->firstWhere('tool', 'composer'))->toMatchArray(['tool' => 'composer', 'status' => 'ok', 'path' => '/usr/bin/php /usr/local/bin/composer']);

    expect($items->firstWhere('tool', 'java'))->toMatchArray(['tool' => 'java', 'status' => 'missing']);
    expect($items->firstWhere('tool', 'keytool'))->toMatchArray(['tool' => 'keytool', 'status' => 'missing']);

    expect($response->json('data.ini.fpm.ini_path'))->toBe('/etc/php.ini');
    expect($response->json('data.ini.cli.ini_path'))->toBe('/etc/php-cli.ini');
});
