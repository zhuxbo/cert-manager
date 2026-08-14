<?php

declare(strict_types=1);

use App\Services\Composer\ComposerVendorBundle;

$hostBackendDir = dirname(__DIR__);
$targetBackendDir = realpath($argv[1] ?? $hostBackendDir);
if (! is_string($targetBackendDir) || ! is_dir($targetBackendDir)) {
    throw new RuntimeException('无法写入 vendor 标记：Composer 项目目录不存在');
}

require $hostBackendDir.'/vendor/autoload.php';

ComposerVendorBundle::writeMarker($targetBackendDir);
