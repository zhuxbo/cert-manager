<?php

namespace App\Traits;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * 纯本地 task→order/acme 变更事务的统一重试入口
 *
 * 把「先锁 task（Task::lockForMutation 强制复合索引）再锁业务行」的纯本地变更事务
 * 统一包在 DB::transaction(..., 3) 内，遇 1213/1205 由 Laravel 自动重试。
 * 含上游副作用的 commit/cancel 不走此助手（绝不事务级重试，防重复下单/退款）。
 * Order\Action 与 Acme\Action 共用。
 */
trait RunsTaskMutationTransaction
{
    private const TASK_MUTATION_TRANSACTION_ATTEMPTS = 3;

    /**
     * 在带 3 次死锁重试的事务内执行纯本地 task 变更闭包
     */
    protected function runTaskMutationTransaction(Closure $callback): mixed
    {
        return DB::transaction($callback, self::TASK_MUTATION_TRANSACTION_ATTEMPTS);
    }
}
