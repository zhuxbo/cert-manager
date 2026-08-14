<?php

return [
    'operations' => [
        'timeout' => (int) env('PLUGIN_OPERATION_TIMEOUT', 720),
        'timeout_margin' => (int) env('PLUGIN_OPERATION_TIMEOUT_MARGIN', 60),
        'stale_after' => (int) env('PLUGIN_OPERATION_STALE_AFTER', 600),
        'overhead_margin' => (int) env('PLUGIN_OPERATION_OVERHEAD_MARGIN', 30),
        'artisan_timeout' => (int) env('PLUGIN_OPERATION_ARTISAN_TIMEOUT', 60),
        'cleanup_finished_after' => (int) env('PLUGIN_OPERATION_CLEANUP_FINISHED_AFTER', 86400),
    ],

    'download' => [
        // curl 与 HTTP fallback 各自拥有完整的单次超时，最坏下载阶段为该值的 2 倍。
        'timeout' => (int) env('PLUGIN_DOWNLOAD_TIMEOUT', 120),
    ],

    'upgrade' => [
        // 首次采用启动锁时，旧请求尚未持共享锁；先注入入口并按 FPM 请求上限排空。
        'legacy_request_drain_timeout' => (int) env('UPGRADE_LEGACY_REQUEST_DRAIN_TIMEOUT', 300),
    ],

    'composer' => [
        'timeout' => (int) env('PLUGIN_COMPOSER_TIMEOUT', 210),
    ],
];
