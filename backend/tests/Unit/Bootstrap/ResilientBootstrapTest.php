<?php

// 锁定 bootstrap/cache TOCTOU 兜底重试器（bootstrap/resilient.php）契约：
// 只对 services.php/packages.php 的「读失败」重试，其余异常原样抛；有重试上限。
// 纯逻辑单元测试，不碰 DB、不触真实框架 bootstrap，不受 services.php flake 影响。

use Tests\TestCase;

uses(TestCase::class);

test('services.php 读失败时清缓存并退避重试直到成功', function () {
    $resilient = require base_path('bootstrap/resilient.php');

    $calls = 0;
    $result = $resilient(function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw new ErrorException(
                'require(/var/www/bootstrap/cache/services.php): Failed to open stream: No such file or directory'
            );
        }

        return 'ok';
    }, sys_get_temp_dir());

    expect($result)->toBe('ok')->and($calls)->toBe(3);
});

test('packages.php 读失败同样触发重试', function () {
    $resilient = require base_path('bootstrap/resilient.php');

    $calls = 0;
    $resilient(function () use (&$calls) {
        $calls++;
        if ($calls < 2) {
            throw new ErrorException(
                'require(/x/bootstrap/cache/packages.php): Failed to open stream: No such file'
            );
        }

        return null;
    }, sys_get_temp_dir());

    expect($calls)->toBe(2);
});

test('非 bootstrap-cache 异常不重试、原样抛出', function () {
    $resilient = require base_path('bootstrap/resilient.php');

    $calls = 0;
    expect(function () use ($resilient, &$calls) {
        return $resilient(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('业务错误：订单不存在');
        }, sys_get_temp_dir());
    })->toThrow(RuntimeException::class, '业务错误：订单不存在');

    expect($calls)->toBe(1);
});

test('超过重试上限（3 次）后抛出原异常', function () {
    $resilient = require base_path('bootstrap/resilient.php');

    $calls = 0;
    expect(function () use ($resilient, &$calls) {
        return $resilient(function () use (&$calls) {
            $calls++;
            throw new ErrorException(
                'require(/x/bootstrap/cache/services.php): Failed to open stream: No such file'
            );
        }, sys_get_temp_dir());
    })->toThrow(ErrorException::class);

    expect($calls)->toBe(4); // 首次 + 3 次重试
});
