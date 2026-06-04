<?php

use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

test('AppServiceProvider 同步 mysql 连接 timezone 为数字偏移（避开 MySQL 时区表依赖）', function () {
    $appTz = config('app.timezone');
    $mysqlTz = config('database.connections.mysql.timezone');

    expect($mysqlTz)
        ->not->toBeNull()
        ->toMatch('/^[+-]\d{2}:\d{2}$/');

    // 数字偏移和 app.timezone 当前的实际偏移一致
    $appOffset = (new DateTimeZone($appTz))->getOffset(new DateTime);
    $mysqlOffset = (new DateTimeZone($mysqlTz))->getOffset(new DateTime);
    expect($mysqlOffset)->toBe($appOffset);
});

test('AppServiceProvider 启动期接管 Carbon::setLocale', function () {
    expect(Carbon::getLocale())->toBe(config('app.locale', 'zh_CN'));
});
