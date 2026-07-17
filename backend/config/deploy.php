<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deploy callback failure aggregation
    |--------------------------------------------------------------------------
    |
    | 下游部署工具回调 failure 时服务端留痕（error_logs）+ 按 order 7 天滑窗聚合告警。
    | 下游 spec 约定「每天执行一次续签检查」→ 单证书每天至多 1 次 failure 回调、200 ack
    | 不重试，故用「7 天滑窗计数 ≥ threshold」而非 24h tumbling（后者在日频节奏下恒不可达）。
    | 去重交 SystemAlert（per-order dedupeKey + 固定指纹 + dedupe_ttl_hours 周提醒一封）。
    |
    */

    'callback_failure' => [
        'threshold' => (int) env('DEPLOY_CALLBACK_FAILURE_THRESHOLD', 2),

        'window_days' => (int) env('DEPLOY_CALLBACK_FAILURE_WINDOW_DAYS', 7),

        'dedupe_ttl_hours' => (int) env('DEPLOY_CALLBACK_FAILURE_DEDUPE_TTL_HOURS', 168),
    ],
];
