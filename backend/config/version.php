<?php

/**
 * 版本配置
 *
 * 从项目根目录的 version.json 读取版本信息
 */

// 读取版本信息
$versionJson = [];
$versionPaths = [
    dirname(__DIR__, 2).'/version.json',  // 项目根目录（标准部署）
    dirname(__DIR__).'/version.json',     // backend 目录（兼容旧版本路径）
];

foreach ($versionPaths as $versionPath) {
    if (file_exists($versionPath)) {
        $content = file_get_contents($versionPath);
        $versionJson = json_decode($content, true) ?: [];
        if (! empty($versionJson)) {
            break;
        }
    }
}

return [
    // 当前版本号（语义化版本）
    'version' => $versionJson['version'] ?? '0.0.0-beta',

    // 版本名称
    'name' => 'SSL Manager',

    // 构建时间
    'build_time' => $versionJson['build_time'] ?? '',

    // 构建 commit
    'build_commit' => $versionJson['build_commit'] ?? '',

    // 发布通道：main（正式版）或 dev（开发版）
    'channel' => $versionJson['channel'] ?? 'dev',
];
