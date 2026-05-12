<?php

use App\Services\Upgrade\VersionManager;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Config::set('version', [
        'version' => '1.0.0',
        'channel' => 'main',
    ]);
    Config::set('upgrade.constraints.allow_downgrade', false);

    // 使用部分 mock，覆盖文件读取方法（protected 方法）
    $this->versionManager = Mockery::mock(VersionManager::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $this->versionManager->shouldReceive('getVersionJson')
        ->andReturn([
            'version' => '1.0.0',
            'channel' => 'main',
        ]);
});

afterEach(function () {
    Mockery::close();
});

test('get current version', function () {
    $version = $this->versionManager->getCurrentVersion();

    expect($version)->toBeArray();
    expect($version['version'])->toBe('1.0.0');
    expect($version['channel'])->toBe('main');
});

test('get channel', function () {
    expect($this->versionManager->getChannel())->toBe('main');
});

test('get version string', function () {
    expect($this->versionManager->getVersionString())->toBe('1.0.0');
});

test('compare versions greater', function () {
    expect($this->versionManager->compareVersions('2.0.0', '1.0.0'))->toBe(1);
    expect($this->versionManager->compareVersions('1.1.0', '1.0.0'))->toBe(1);
    expect($this->versionManager->compareVersions('1.0.1', '1.0.0'))->toBe(1);
});

test('compare versions less', function () {
    expect($this->versionManager->compareVersions('1.0.0', '2.0.0'))->toBe(-1);
    expect($this->versionManager->compareVersions('1.0.0', '1.1.0'))->toBe(-1);
    expect($this->versionManager->compareVersions('1.0.0', '1.0.1'))->toBe(-1);
});

test('compare versions equal', function () {
    expect($this->versionManager->compareVersions('1.0.0', '1.0.0'))->toBe(0);
});

test('compare versions with prerelease', function () {
    // 正式版 > 预发布版
    expect($this->versionManager->compareVersions('1.0.0', '1.0.0-dev'))->toBe(1);
    expect($this->versionManager->compareVersions('1.0.0-dev', '1.0.0'))->toBe(-1);
    // 相同预发布版本
    expect($this->versionManager->compareVersions('1.0.0-dev', '1.0.0-dev'))->toBe(0);
});

test('compare versions with v prefix', function () {
    expect($this->versionManager->compareVersions('v1.0.0', '1.0.0'))->toBe(0);
    expect($this->versionManager->compareVersions('V1.0.0', 'v1.0.0'))->toBe(0);
    expect($this->versionManager->compareVersions('v2.0.0', 'v1.0.0'))->toBe(1);
});

test('is upgrade allowed newer version', function () {
    expect($this->versionManager->isUpgradeAllowed('2.0.0'))->toBeTrue();
    expect($this->versionManager->isUpgradeAllowed('1.1.0'))->toBeTrue();
    expect($this->versionManager->isUpgradeAllowed('1.0.1'))->toBeTrue();
});

test('is upgrade allowed same version', function () {
    // 相同版本不需要升级
    expect($this->versionManager->isUpgradeAllowed('1.0.0'))->toBeFalse();
});

test('is upgrade allowed older version', function () {
    // 默认不允许降级
    expect($this->versionManager->isUpgradeAllowed('0.9.0'))->toBeFalse();
    expect($this->versionManager->isUpgradeAllowed('0.9.9'))->toBeFalse();
});

test('is upgrade allowed with downgrade enabled', function () {
    Config::set('upgrade.constraints.allow_downgrade', true);

    // 允许降级时可以"升级"到旧版本
    expect($this->versionManager->isUpgradeAllowed('0.9.0'))->toBeTrue();
});

test('check php version', function () {
    Config::set('upgrade.constraints.min_php_version', '8.0.0');
    expect($this->versionManager->checkPhpVersion())->toBeTrue();

    Config::set('upgrade.constraints.min_php_version', '99.0.0');
    expect($this->versionManager->checkPhpVersion())->toBeFalse();
});

test('get min php version', function () {
    Config::set('upgrade.constraints.min_php_version', '8.3.0');
    expect($this->versionManager->getMinPhpVersion())->toBe('8.3.0');
});
