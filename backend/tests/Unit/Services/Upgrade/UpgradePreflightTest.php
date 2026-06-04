<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Upgrade\UpgradePreflight;
use Tests\TestCase;

uses(TestCase::class);

test('check 在所有项正常时返回 blocking 空数组', function () {
    $locatorMock = $this->mock(BinaryLocator::class);
    $locatorMock->shouldReceive('php')->andReturn('/www/server/php/84/bin/php');
    $locatorMock->shouldReceive('composer')->andReturn('/www/server/php/84/bin/php /usr/local/bin/composer');
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn([
        'ini_path' => '/www/server/php/84/etc/php.ini',
        'disable_functions' => '',
        'disable_functions_ok' => true,
    ]);
    $locatorMock->shouldReceive('inspectCliIni')->andReturn([
        'ini_path' => '/www/server/php/84/etc/php-cli.ini',
        'disable_functions' => '',
        'disable_functions_ok' => true,
    ]);

    $preflight = new UpgradePreflight($locatorMock);
    $result = $preflight->check();

    expect($result['blocking'])->toBeEmpty();
    expect($result['ini']['fpm']['disable_functions_ok'])->toBeTrue();
    expect($result['ini']['cli']['disable_functions_ok'])->toBeTrue();
});

test('check 在 FPM disable_functions 禁了 proc_open 时返回 fpm_proc_open_disabled', function () {
    $locatorMock = $this->mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn([
        'ini_path' => '/etc/php.ini',
        'disable_functions' => 'proc_open,exec',
        'disable_functions_ok' => false,
    ]);
    $locatorMock->shouldReceive('inspectCliIni')->andReturn([
        'ini_path' => '/etc/php-cli.ini',
        'disable_functions' => '',
        'disable_functions_ok' => true,
    ]);
    $locatorMock->shouldReceive('php')->andReturn('/usr/bin/php');
    $locatorMock->shouldReceive('composer')->andReturn('/usr/bin/php /usr/local/bin/composer');

    $codes = array_column((new UpgradePreflight($locatorMock))->check()['blocking'], 'code');

    expect($codes)->toContain('fpm_proc_open_disabled');
});

test('check 在 PHP CLI 找不到时返回 php_cli_missing + fix 文案不含绝对路径', function () {
    $locatorMock = $this->mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn(['disable_functions_ok' => true, 'ini_path' => '']);
    $locatorMock->shouldReceive('php')->andThrow(
        new BinaryNotFoundException(
            tool: 'php', triedPaths: ['/usr/bin/php'], diagnose: ['...']
        )
    );
    $locatorMock->shouldReceive('composer')->andReturn('/usr/bin/php /usr/local/bin/composer');
    // 注：php 失败时 inspectCliIni 不应被调（实现里有 $phpOk 兜底）

    $blocking = (new UpgradePreflight($locatorMock))->check()['blocking'];
    $php = collect($blocking)->firstWhere('code', 'php_cli_missing');

    expect($php)->not->toBeNull();
    expect($php['fix'])->toBe('使用 upgrade.sh 升级');
    // fix 不应出现服务器绝对路径
    expect($php['fix'])->not->toContain('/');
});

test('check 在 composer 找不到时返回 composer_missing', function () {
    $locatorMock = $this->mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn(['disable_functions_ok' => true, 'ini_path' => '']);
    $locatorMock->shouldReceive('inspectCliIni')->andReturn(['disable_functions_ok' => true, 'ini_path' => '']);
    $locatorMock->shouldReceive('php')->andReturn('/usr/bin/php');
    $locatorMock->shouldReceive('composer')->andThrow(
        new BinaryNotFoundException(
            tool: 'composer', triedPaths: [], diagnose: []
        )
    );

    $codes = array_column((new UpgradePreflight($locatorMock))->check()['blocking'], 'code');

    expect($codes)->toContain('composer_missing');
});

test('check 在 CLI disable_functions 禁了 proc_open 时返回 cli_proc_open_disabled', function () {
    $locatorMock = $this->mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andReturn(['disable_functions_ok' => true, 'ini_path' => '']);
    $locatorMock->shouldReceive('inspectCliIni')->andReturn([
        'ini_path' => '/www/server/php/84/etc/php-cli.ini',
        'disable_functions' => 'proc_open',
        'disable_functions_ok' => false,
    ]);
    $locatorMock->shouldReceive('php')->andReturn('/usr/bin/php');
    $locatorMock->shouldReceive('composer')->andReturn('/usr/bin/php /usr/local/bin/composer');

    $blocking = (new UpgradePreflight($locatorMock))->check()['blocking'];
    $cli = collect($blocking)->firstWhere('code', 'cli_proc_open_disabled');

    expect($cli)->not->toBeNull();
    expect($cli['fix'])->toContain('php-cli.ini');
});

test('check 在 BinaryLocator 抛非 BinaryNotFoundException 异常时不冒泡', function () {
    $locatorMock = $this->mock(BinaryLocator::class);
    $locatorMock->shouldReceive('inspectFpmIni')->andThrow(new Exception('意外错误'));
    // 其它方法不会被调（第一步就 fail）

    $result = (new UpgradePreflight($locatorMock))->check();

    // 期望返回 blocking 含 health_check_failed code，不能让 execute 端点 500
    expect(collect($result['blocking'])->pluck('code'))->toContain('health_check_failed');
    expect($result['items'])->toBe([]);
    expect($result['ini'])->toHaveKeys(['fpm', 'cli']);
});
