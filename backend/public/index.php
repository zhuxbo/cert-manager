<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// SSL_MANAGER_BOOTSTRAP_LOCK_V1_BEGIN
// Hold a shared bootstrap lock for the whole HTTP request. Upgrade and plugin
// publishers take the exclusive lock while replacing runtime files, so no
// request can observe a missing or partially updated application tree.
$upgradeBootstrapLock = @fopen(__DIR__.'/../.upgrade-bootstrap.lock', 'c');
if ($upgradeBootstrapLock !== false) {
    if (flock($upgradeBootstrapLock, LOCK_SH)) {
        register_shutdown_function(static function () use ($upgradeBootstrapLock): void {
            flock($upgradeBootstrapLock, LOCK_UN);
            fclose($upgradeBootstrapLock);
        });
    } else {
        fclose($upgradeBootstrapLock);
    }
}
// SSL_MANAGER_BOOTSTRAP_LOCK_V1_END

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
// 用 bootstrap/cache TOCTOU 兜底包裹启动：services.php/packages.php 在并发编译 / 升级窗口 /
// VirtioFS 下偶发 "Failed to open stream"，此时清半态缓存 + 退避重试（详见 bootstrap/resilient.php）
$resilient = require __DIR__.'/../bootstrap/resilient.php';
$resilient(static function () {
    (require __DIR__.'/../bootstrap/app.php')
        ->handleRequest(Request::capture());
}, __DIR__.'/../bootstrap/cache');
