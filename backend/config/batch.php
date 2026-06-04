<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 批量接口 ids 数组上限
    |--------------------------------------------------------------------------
    |
    | max_ids：所有 GetIdsRequest 的 ids 数组元素数量上限（粗粒度闸门，
    |   防止超大 ids 数组撑爆 whereIn / exists 校验与内存，造成资源耗尽）。
    |
    | max_upstream：批量操作中「逐条调用上游 API / 逐条独立事务」的危险循环
    |   上限（如 batchCommitCancel / batchRevokeCancel / pay）。比 max_ids 更严，
    |   作为 Request 层之外的第二道闸门，避免单次请求触发过多上游调用。
    |
    */

    'max_ids' => (int) env('BATCH_MAX_IDS', 100),

    'max_upstream' => (int) env('BATCH_MAX_UPSTREAM', 20),
];
