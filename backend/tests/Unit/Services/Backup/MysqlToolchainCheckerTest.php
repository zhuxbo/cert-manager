<?php

use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->scripts = [];
});

afterEach(function () {
    foreach ($this->scripts as $script) {
        @unlink($script);
        @rmdir(dirname($script));
    }
});

function mysqlToolchainScript(object $test, string $name, string $output): string
{
    $directory = sys_get_temp_dir().'/mysql toolchain '.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $path = $directory.'/'.$name;
    file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($output)."\n");
    chmod($path, 0700);
    $test->scripts[] = $path;

    return $path;
}

function mysqlToolchainChecker(object $test, array $versions, string $serverVersion, string $serverComment): MysqlToolchainChecker
{
    $locator = Mockery::mock(BinaryLocator::class);
    foreach ($versions as $tool => $output) {
        $locator->shouldReceive($tool)->once()->andReturn(mysqlToolchainScript($test, $tool, $output));
    }

    DB::shouldReceive('selectOne')
        ->once()
        ->with('SELECT VERSION() AS version, @@version_comment AS version_comment')
        ->andReturn((object) ['version' => $serverVersion, 'version_comment' => $serverComment]);

    return new MysqlToolchainChecker($locator);
}

test('接受同系列 Oracle MySQL 5.7 客户端与服务端', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 14.14 Distrib 5.7.44, for Linux (x86_64) using EditLine wrapper',
        'mysqldump' => 'mysqldump  Ver 10.13 Distrib 5.7.44, for Linux (x86_64)',
        'gzip' => 'gzip 1.12',
    ], '5.7.44-log', 'MySQL Community Server (GPL)');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeTrue()
        ->and($inspection['server'])->toBe(['vendor' => 'mysql', 'version' => '5.7.44', 'series' => '5.7'])
        ->and($inspection['mysql'])->toMatchArray(['vendor' => 'mysql', 'version' => '5.7.44', 'series' => '5.7'])
        ->and($inspection['mysqldump'])->toMatchArray(['vendor' => 'mysql', 'version' => '5.7.44', 'series' => '5.7'])
        ->and($inspection['gzip']['version'])->toBe('1.12');
});

test('接受同系列 Oracle MySQL 8.0 与 8.4，补丁版本可不同', function (string $version, string $clientVersion, string $series) {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => "mysql  Ver {$clientVersion} for Linux on x86_64 (MySQL Community Server - GPL)",
        'mysqldump' => "mysqldump  Ver {$clientVersion} for Linux on x86_64 (MySQL Community Server - GPL)",
        'gzip' => 'gzip 1.12',
    ], $version, 'MySQL Community Server - GPL');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeTrue()
        ->and($inspection['server']['series'])->toBe($series)
        ->and($inspection['mysql']['series'])->toBe($series)
        ->and($inspection['errors'])->toBe([]);
})->with([
    ['8.0.41', '8.0.40', '8.0'],
    ['8.4.3', '8.4.1', '8.4'],
]);

test('接受宝塔源码安装的 Oracle MySQL Source distribution', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 8.0.35 for Linux on x86_64 (Source distribution)',
        'mysqldump' => 'mysqldump  Ver 8.0.35 for Linux on x86_64 (Source distribution)',
        'gzip' => 'gzip 1.12',
    ], '8.0.35', 'Source distribution');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeTrue()
        ->and($inspection['server'])->toBe(['vendor' => 'mysql', 'version' => '8.0.35', 'series' => '8.0'])
        ->and($inspection['mysql'])->toMatchArray(['vendor' => 'mysql', 'version' => '8.0.35', 'series' => '8.0'])
        ->and($inspection['mysqldump'])->toMatchArray(['vendor' => 'mysql', 'version' => '8.0.35', 'series' => '8.0'])
        ->and($inspection['errors'])->toBe([]);
});

test('拒绝 MariaDB 客户端和服务端', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 15.1 Distrib 10.11.6-MariaDB, for debian-linux-gnu (x86_64) using EditLine wrapper',
        'mysqldump' => 'mysqldump  Ver 10.19 Distrib 10.11.6-MariaDB, for debian-linux-gnu (x86_64)',
        'gzip' => 'gzip 1.12',
    ], '10.11.6-MariaDB-0+deb12u1', 'Debian 12');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection['server'])->toBe(['vendor' => 'mariadb', 'version' => '10.11.6', 'series' => '10.11'])
        ->and($inspection['mysql']['vendor'])->toBe('mariadb')
        ->and($inspection['mysqldump']['vendor'])->toBe('mariadb')
        ->and($inspection['errors'])->toHaveCount(1)
        ->and($inspection['errors'][0])->toContain('服务端为 MariaDB')
        ->toContain('改用 Oracle MySQL');
});

test('拒绝与服务端系列不一致的官方 MySQL 客户端', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 8.0.41 for Linux on x86_64 (MySQL Community Server - GPL)',
        'mysqldump' => 'mysqldump  Ver 8.0.41 for Linux on x86_64 (MySQL Community Server - GPL)',
        'gzip' => 'gzip 1.12',
    ], '8.4.3', 'MySQL Community Server - GPL');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and(implode("\n", $inspection['errors']))->toContain('系列不一致')
        ->toContain('8.4')
        ->toContain('8.0');
});

test('拒绝白名单外的 Oracle MySQL 系列，并展示检测到的事实', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 8.1.0 for Linux on x86_64 (MySQL Community Server - GPL)',
        'mysqldump' => 'mysqldump  Ver 8.1.0 for Linux on x86_64 (MySQL Community Server - GPL)',
        'gzip' => 'gzip 1.12',
    ], '8.1.0', 'MySQL Community Server - GPL');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection['server'])->toBe(['vendor' => 'mysql', 'version' => '8.1.0', 'series' => '8.1'])
        ->and(implode("\n", $inspection['errors']))->toContain('Oracle MySQL 8.1.0')
        ->toContain('仅支持 5.7、8.0、8.4 系列');
});

test('拒绝 Percona 服务端', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'mysqldump' => 'mysqldump  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'gzip' => 'gzip 1.12',
    ], '8.4.9-9', 'Percona Server (GPL), Release 9, Revision abc');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection['server'])->toBe(['vendor' => 'percona', 'version' => '8.4.9', 'series' => '8.4'])
        ->and(implode("\n", $inspection['errors']))->toContain('服务端为 Percona 8.4.9')
        ->toContain('请改用 Oracle MySQL');
});

test('拒绝无法明确识别为 Oracle MySQL 的其它服务端', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'mysqldump' => 'mysqldump  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'gzip' => 'gzip 1.12',
    ], '8.4.9', 'ExampleDB Server');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection['server'])->toBe(['vendor' => 'unknown', 'version' => '8.4.9', 'series' => '8.4'])
        ->and(implode("\n", $inspection['errors']))->toContain('服务端类型无法确认')
        ->toContain('8.4.9')
        ->toContain('VERSION()');
});

test('拒绝 Percona 客户端', function (string $tool) {
    $versions = [
        'mysql' => 'mysql  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'mysqldump' => 'mysqldump  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'gzip' => 'gzip 1.12',
    ];
    $versions[$tool] = "$tool  Ver 8.4.1-1 for Linux on x86_64 (Percona Server (GPL), Release 1)";
    $checker = mysqlToolchainChecker($this, $versions, '8.4.9', 'MySQL Community Server - GPL');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection[$tool]['vendor'])->toBe('percona')
        ->and(implode("\n", $inspection['errors']))->toContain("$tool 客户端")
        ->toContain('Percona 8.4.1')
        ->toContain('请改用 Oracle MySQL');
})->with(['mysql', 'mysqldump']);

test('MariaDB 仅出现在任一组件时仍拒绝', function (string $component) {
    $versions = [
        'mysql' => 'mysql  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'mysqldump' => 'mysqldump  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'gzip' => 'gzip 1.12',
    ];
    $serverVersion = '8.4.9';
    $serverComment = 'MySQL Community Server - GPL';
    if ($component === 'server') {
        $serverVersion = '10.11.6-MariaDB';
        $serverComment = 'MariaDB Server';
    } else {
        $versions[$component] = "$component  Ver 10.11.6-MariaDB for Linux on x86_64";
    }
    $checker = mysqlToolchainChecker($this, $versions, $serverVersion, $serverComment);

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection[$component]['vendor'])->toBe('mariadb')
        ->and(implode("\n", $inspection['errors']))->toContain('MariaDB 10.11.6');
})->with(['server', 'mysql', 'mysqldump']);

test('MariaDB 客户端提示准确引用已检测到的 Oracle MySQL 服务端版本', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 15.1 Distrib 10.11.13-MariaDB, for debian-linux-gnu (x86_64)',
        'gzip' => 'gzip 1.12',
    ], '8.4.0', 'MySQL Community Server - GPL');

    $inspection = $checker->inspect(true, false);
    expect($inspection['supported'])->toBeFalse()
        ->and($inspection['server'])->toBe(['vendor' => 'mysql', 'version' => '8.4.0', 'series' => '8.4'])
        ->and($inspection['mysql'])->toMatchArray(['vendor' => 'mariadb', 'version' => '10.11.13'])
        ->and($inspection['errors'])->toBe([
            '当前 mysql 客户端为 MariaDB 10.11.13，不受支持；目标服务端为 MySQL 8.4.0，请改用 MySQL 8.4 系列客户端。',
        ]);
});

test('gzip 缺失时保留 BinaryNotFoundException 的已有诊断', function () {
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('mysql')->once()->andReturn(mysqlToolchainScript($this, 'mysql', 'mysql  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)'));
    $locator->shouldReceive('mysqldump')->once()->andReturn(mysqlToolchainScript($this, 'mysqldump', 'mysqldump  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)'));
    $locator->shouldReceive('gzip')->once()->andThrow(new BinaryNotFoundException('gzip', ['/missing/gzip'], ['已有 gzip 诊断']));
    DB::shouldReceive('selectOne')->once()->andReturn((object) ['version' => '8.4.9', 'version_comment' => 'MySQL Community Server - GPL']);

    $inspection = (new MysqlToolchainChecker($locator))->inspect(true, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection['gzip'])->toBe(['path' => '', 'version' => 'unknown'])
        ->and(implode("\n", $inspection['errors']))->toContain('/missing/gzip')
        ->toContain('已有 gzip 诊断');
});

test('可选 mysql 缺失时不阻塞，但必需 mysqldump 缺失时给出独立客户端安装提示', function () {
    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldNotReceive('mysql');
    $locator->shouldReceive('mysqldump')->once()->andThrow(new BinaryNotFoundException('mysqldump', ['/missing/mysqldump']));
    $locator->shouldReceive('gzip')->once()->andReturn(mysqlToolchainScript($this, 'gzip', 'gzip 1.12'));
    DB::shouldReceive('selectOne')->once()->andReturn((object) ['version' => '8.4.3', 'version_comment' => 'MySQL Community Server - GPL']);

    $inspection = (new MysqlToolchainChecker($locator))->inspect(false, true);

    expect($inspection['supported'])->toBeFalse()
        ->and($inspection['mysql'])->toBeNull()
        ->and($inspection['mysqldump'])->toBeNull()
        ->and($inspection['errors'])->toHaveCount(2)
        ->and($inspection['errors'][0])->toContain('未找到 mysqldump 客户端')
        ->toContain('/www/server/mysql/bin/mysqldump')
        ->and($inspection['errors'][1])->toContain('Oracle MySQL 8.4 系列客户端')
        ->toContain('apt-get install -y mysql-client')
        ->toContain('dnf install -y mysql-community-client')
        ->and(implode("\n", $inspection['errors']))->not->toContain('open_basedir');
});

test('版本命令以参数数组执行，支持包含空格的绝对路径', function () {
    $checker = mysqlToolchainChecker($this, [
        'mysql' => 'mysql  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'mysqldump' => 'mysqldump  Ver 8.4.1 for Linux on x86_64 (MySQL Community Server - GPL)',
        'gzip' => 'gzip 1.13',
    ], '8.4.3', 'MySQL Community Server - GPL');

    $inspection = $checker->inspect(true, true);

    expect($inspection['supported'])->toBeTrue()
        ->and($inspection['mysql']['path'])->toContain('mysql toolchain')
        ->and($inspection['mysqldump']['path'])->toContain('mysql toolchain');
});
