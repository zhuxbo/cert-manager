<?php

return [
    'operations' => [
        'timeout' => (int) env('PLUGIN_OPERATION_TIMEOUT', 480),
        'timeout_margin' => (int) env('PLUGIN_OPERATION_TIMEOUT_MARGIN', 60),
        'stale_after' => (int) env('PLUGIN_OPERATION_STALE_AFTER', 600),
        'overhead_margin' => (int) env('PLUGIN_OPERATION_OVERHEAD_MARGIN', 30),
        'artisan_timeout' => (int) env('PLUGIN_OPERATION_ARTISAN_TIMEOUT', 60),
        'cleanup_finished_after' => (int) env('PLUGIN_OPERATION_CLEANUP_FINISHED_AFTER', 86400),
    ],

    'download' => [
        'timeout' => (int) env('PLUGIN_DOWNLOAD_TIMEOUT', 30),
    ],

    'composer' => [
        'timeout' => (int) env('PLUGIN_COMPOSER_TIMEOUT', 210),
    ],
];
