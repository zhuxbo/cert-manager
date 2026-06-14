<?php

use App\Services\Composer\ComposerMirror;
use Tests\TestCase;

uses(TestCase::class);

// ==================== setAliyunCommand ====================

test('setAliyunCommand 生成含阿里云源的命令且路径 escape、不走样', function () {
    $mirror = new ComposerMirror;

    $cmd = $mirror->setAliyunCommand('/var/www/plugin/backend', "'/usr/bin/php' '/usr/local/bin/composer'");

    // 阿里云镜像源字面量未走样
    expect($cmd)->toContain('config repo.packagist composer https://mirrors.aliyun.com/composer/');
    // 路径走 escapeshellarg，composer 命令前缀原样拼入
    expect($cmd)->toContain(escapeshellarg('/var/www/plugin/backend'));
    expect($cmd)->toContain("'/usr/bin/php' '/usr/local/bin/composer'");
    // cd 前缀 + 2>&1 合并 stderr，整体形态与抽取前逐字一致
    expect($cmd)->toBe(
        "cd '/var/www/plugin/backend' && '/usr/bin/php' '/usr/local/bin/composer' config repo.packagist composer https://mirrors.aliyun.com/composer/ 2>&1"
    );
});

// ==================== resetCommand ====================

test('resetCommand 生成 unset repo.packagist 的命令且路径 escape', function () {
    $mirror = new ComposerMirror;

    $cmd = $mirror->resetCommand('/var/www/plugin/backend', "'php' 'composer'");

    expect($cmd)->toContain('config --unset repo.packagist');
    expect($cmd)->toContain(escapeshellarg('/var/www/plugin/backend'));
    expect($cmd)->toBe(
        "cd '/var/www/plugin/backend' && 'php' 'composer' config --unset repo.packagist 2>&1"
    );
});

// ==================== escapeshellarg 处理含特殊字符路径 ====================

test('命令构造对含空格 / 引号的路径正确 escape', function () {
    $mirror = new ComposerMirror;

    $weird = "/tmp/a b'c";
    $cmd = $mirror->setAliyunCommand($weird, "'composer'");

    // 含空格 + 单引号的路径整段被 escapeshellarg 包裹，不裸露
    expect($cmd)->toContain(escapeshellarg($weird));
    expect($cmd)->not->toContain(" $weird ");
});

// ==================== networkReachable ====================

test('networkReachable 对不可达地址返回 false（可控分支）', function () {
    $mirror = new ComposerMirror;

    // 保留 TEST-NET-1（RFC 5737）不可路由地址 + 1s 超时，确保返回 false 分支可控
    $result = $mirror->networkReachable('http://192.0.2.1:9/never', 1);

    expect($result)->toBeFalse();
});

test('networkReachable 始终返回 bool', function () {
    $mirror = new ComposerMirror;

    expect($mirror->networkReachable('http://localhost', 1))->toBeBool();
});
