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
];
