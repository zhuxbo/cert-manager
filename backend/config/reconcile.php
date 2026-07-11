<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stale pending order reconciliation
    |--------------------------------------------------------------------------
    |
    | Orders left in certs.status=pending with no api_id are paid but have not
    | been submitted upstream. This can happen after M1 if the request exits
    | after new+pay commits and before the out-of-transaction commit runs.
    |
    */

    'pending_stale_minutes' => (int) env('RECONCILE_PENDING_STALE_MINUTES', 10),

    'max_reconcile_ids' => (int) env('RECONCILE_MAX_IDS', 20),

    'max_attempts' => (int) env('RECONCILE_MAX_ATTEMPTS', 3),

    'retry_delay_minutes' => (int) env('RECONCILE_RETRY_DELAY_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Stale executing task sweeper (T1)
    |--------------------------------------------------------------------------
    |
    | 僵尸 executing 任务（dispatch 后 worker 未消费、永久卡 executing）的重派兜底。
    | stale_minutes：started_at/last_execute_at 滞后多久算僵尸；max_redispatch：重派上限，
    | 超限置 stopped 转人工；min_interval_minutes：两次重派最小间隔（观察期）；batch：单轮扫描上限。
    |
    */
    'sweeper' => [
        'stale_minutes' => (int) env('RECONCILE_SWEEPER_STALE_MINUTES', 30),
        'max_redispatch' => (int) env('RECONCILE_SWEEPER_MAX_REDISPATCH', 3),
        'min_interval_minutes' => (int) env('RECONCILE_SWEEPER_MIN_INTERVAL_MINUTES', 30),
        'batch' => (int) env('RECONCILE_SWEEPER_BATCH', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale pending ACME order reconciliation (T6)
    |--------------------------------------------------------------------------
    |
    | 镜像 Order reconcile：pending 且无 api_id 的 ACME 订单重发 commit。ACME 无 0~8h 延时正常态
    | （commit 秒级），cutoff 15min 远大于秒级 commit，不误触。到顶超限 → SystemAlert 转人工，不发 user。
    |
    */
    'acme_cutoff_minutes' => (int) env('RECONCILE_ACME_CUTOFF_MINUTES', 15),

    'acme_max_ids' => (int) env('RECONCILE_ACME_MAX_IDS', 20),

    'acme_max_attempts' => (int) env('RECONCILE_ACME_MAX_ATTEMPTS', 3),

    'acme_retry_delay_minutes' => (int) env('RECONCILE_ACME_RETRY_DELAY_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Orphan order sweeper (O4 — schedule:sweep-orphan-orders)
    |--------------------------------------------------------------------------
    |
    | 清理 channel=auto 卡死的孤儿续费/重签单：
    |  - unpaid（stale > unpaid_stale_minutes）→ Action::delete（恢复旧证书、无退款、无流水）
    |  - pending（reconcile 已到顶转人工、非产品缺失）→ Action::cancelPending（退款 + 恢复旧证书）
    |
    | 分级金丝雀（资金重手术）：unpaid 分支默认开（P0 修复即刻生效、无资金面）；pending 退款分支
    | 语义反转为 arm-switch 默认关，首轮生产观察 T5 每日快照确认到顶集正确后手动置 true 开启。
    | pending 到顶判据复用顶层 max_attempts（与 reconcile/T5 同源，绝不另设）。
    |
    */
    'orphan' => [
        'unpaid_enabled' => (bool) env('RECONCILE_ORPHAN_UNPAID_ENABLED', true),
        'pending_enabled' => (bool) env('RECONCILE_ORPHAN_PENDING_ENABLED', false),
        'unpaid_stale_minutes' => (int) env('RECONCILE_ORPHAN_UNPAID_STALE_MINUTES', 60),
        'batch' => (int) env('RECONCILE_ORPHAN_BATCH', 50),
    ],
];
