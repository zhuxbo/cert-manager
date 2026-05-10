<?php

/**
 * 架构白名单：数据库连接清单。
 *
 * 项目仅声明 MySQL 一个连接（兼容 MariaDB）。任何额外连接都视为残留，
 * 多一个 → 立刻红，迫使提交者解释为何引入新 driver。
 *
 * 注意：直接读取项目 `config/database.php` 文件源，而不是 runtime
 * `config('database.connections')` —— 后者会被 Laravel 11 的
 * shouldMergeFrameworkConfiguration() 合并 framework 自带的多种 driver
 * 默认条目，无法用于白名单匹配。我们要抓的是"项目源文件里有人又加了别的连接"。
 */
uses(Tests\TestCase::class);

test('config/database.php 项目源文件 connections 仅声明 mysql', function () {
    $config = require base_path('config/database.php');
    expect(array_keys($config['connections']))->toBe(['mysql']);
});

test('database.default 指向 mysql', function () {
    expect(config('database.default'))->toBe('mysql');
});

test('mysql 连接 driver 为 mysql', function () {
    expect(config('database.connections.mysql.driver'))->toBe('mysql');
});

test('AppServiceProvider 运行期不向 connections 注入新键（只允许写已声明键的属性）', function () {
    // 运行期 connections 应等于"项目源文件 connections" + "framework 自带默认"
    // 抓的是"项目源文件没有 + framework 默认也没有，但 AppServiceProvider 偷偷写进去"的死键
    $projectConfig = require base_path('config/database.php');
    $projectKeys = array_keys($projectConfig['connections']);

    $frameworkConfig = require base_path('vendor/laravel/framework/config/database.php');
    $frameworkKeys = array_keys($frameworkConfig['connections']);

    $allowed = array_unique(array_merge($projectKeys, $frameworkKeys));
    $runtime = array_keys(config('database.connections'));

    $extra = array_diff($runtime, $allowed);
    expect($extra)->toBe([], '发现 ServiceProvider 运行期注入的死连接键: '.implode(', ', $extra));
});
