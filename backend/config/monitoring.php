<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 健康监控命令
    |--------------------------------------------------------------------------
    |
    | 每条监控命令均可经 enabled 独立开关；告警统一走 SystemAlert（code=system_alert）
    | 的状态指纹去重，dedupe_ttl_hours 契约：必须 ≥ 3× 该命令巡检周期（防 TTL≈周期时
    | 去重形同虚设）。停用单一 system_alert 模板（status=0）会令 4 条监控同时静默盲，
    | 这是单 code 复用的固有属性，需知情。
    |
    */

    // E1 上游 CA 凭证健康心跳（schedule:ca-healthcheck，周期 15min）
    // M7：整体连通性维度——连续 connectivity_threshold 次失败才告警（3×15min=45min，滤上游滚动重启瞬断），
    // 固定指纹 ca_outage 去重；connectivity_ttl_hours 契约 ≥ 3×45min。
    'ca_healthcheck' => [
        'enabled' => env('MONITORING_CA_HEALTHCHECK_ENABLED', true),
        'dedupe_ttl_hours' => (int) env('MONITORING_CA_HEALTHCHECK_TTL_HOURS', 24),
        'connectivity_threshold' => (int) env('MONITORING_CA_CONNECTIVITY_THRESHOLD', 3),
        'connectivity_ttl_hours' => (int) env('MONITORING_CA_CONNECTIVITY_TTL_HOURS', 6),
    ],

    // E3 充值渠道健康（schedule:payment-health，周期 1d，周提醒）
    'payment_health' => [
        'enabled' => env('MONITORING_PAYMENT_HEALTH_ENABLED', true),
        'cert_warn_days' => (int) env('MONITORING_PAYMENT_CERT_WARN_DAYS', 30),
        'dedupe_ttl_hours' => (int) env('MONITORING_PAYMENT_HEALTH_TTL_HOURS', 168),
    ],

    // E4 服务器时钟监控（schedule:clock-check，周期 1h）
    // sources：主控已裁默认国内可达源（HTTP Date 头，非 NTP），config/env 可覆盖
    'clock' => [
        'enabled' => env('MONITORING_CLOCK_ENABLED', true),
        'max_skew_seconds' => (int) env('MONITORING_CLOCK_MAX_SKEW_SECONDS', 120),
        'http_timeout_seconds' => (int) env('MONITORING_CLOCK_HTTP_TIMEOUT_SECONDS', 5),
        'sources' => array_values(array_filter(array_map('trim', explode(
            ',',
            env('MONITORING_CLOCK_SOURCES', 'https://www.aliyun.com,https://www.baidu.com')
        )))),
        'dedupe_ttl_hours' => (int) env('MONITORING_CLOCK_TTL_HOURS', 6),
    ],

    // E5 failed_jobs 阈值监控 + prune（schedule:failed-jobs-check，周期 1d；prune weekly）
    'failed_jobs' => [
        'enabled' => env('MONITORING_FAILED_JOBS_ENABLED', true),
        'window_hours' => (int) env('MONITORING_FAILED_JOBS_WINDOW_HOURS', 24),
        'alert_threshold' => (int) env('MONITORING_FAILED_JOBS_ALERT_THRESHOLD', 50),
        'prune_retention_hours' => (int) env('MONITORING_FAILED_JOBS_PRUNE_RETENTION_HOURS', 336),
        'dedupe_ttl_hours' => (int) env('MONITORING_FAILED_JOBS_TTL_HOURS', 72),
    ],
];
