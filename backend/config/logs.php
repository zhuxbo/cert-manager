<?php

/**
 * 全局日志体系配置
 *
 * - buffer_max：LogBuffer 单 model 缓冲达到该值即自动 flush，避免长请求 / 长 Job 内存堆积
 * - retention：7 天全量、180 天动作审计的分层清理窗口
 * - scrubber：LogScrubber 在内置敏感字段 / 正则之外允许通过 env 扩展，便于私有部署
 */
return [
    'buffer_max' => (int) env('LOG_BUFFER_MAX', 200),

    'retention' => [
        'full_days' => (int) env('LOG_RETENTION_FULL_DAYS', 7),
        'audit_days' => (int) env('LOG_RETENTION_AUDIT_DAYS', 180),
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
