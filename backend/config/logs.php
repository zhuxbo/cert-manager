<?php

/**
 * 全局日志体系配置
 *
 * - buffer_max：LogBuffer 单 model 缓冲达到该值即自动 flush，避免长请求 / 长 Job 内存堆积
 * - retention：6 张日志表的清理保留期（天），由 schedule:purge 命令读取
 * - scrubber：LogScrubber 在内置敏感字段 / 正则之外允许通过 env 扩展，便于私有部署
 */
return [
    'buffer_max' => (int) env('LOG_BUFFER_MAX', 200),

    'retention' => [
        'admin' => (int) env('LOG_RETENTION_ADMIN_DAYS', 180),
        'user' => (int) env('LOG_RETENTION_USER_DAYS', 180),
        'api' => (int) env('LOG_RETENTION_API_DAYS', 180),
        'callback' => (int) env('LOG_RETENTION_CALLBACK_DAYS', 180),
        'ca' => (int) env('LOG_RETENTION_CA_DAYS', 180),
        'error' => (int) env('LOG_RETENTION_ERROR_DAYS', 90),
        // GET / OPTIONS 等只读请求保留期更短，单独配置
        'get_only' => (int) env('LOG_RETENTION_GET_DAYS', 30),
    ],

    'scrubber' => [
        // 逗号分隔字段名，如 LOG_SCRUB_EXTRA_FIELDS=internal_secret,vendor_pin
        'extra_fields' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LOG_SCRUB_EXTRA_FIELDS', ''))
        ))),
        // 逗号分隔正则（注意 env 值不能含逗号，需以 base64 / 多次 env 形式拆分）
        'extra_patterns' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LOG_SCRUB_EXTRA_PATTERNS', ''))
        ))),
    ],
];
