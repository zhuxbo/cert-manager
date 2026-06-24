<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * 订单级互斥锁抢占失败：同一订单已有 commit/cancel 正在执行（持锁调上游中）。
 *
 * 故意**不继承** ApiResponseException —— 否则会被 TaskJob 内层 catch(ApiResponseException)
 * 当成业务结果标 failed。独立类型让两路分流：
 *   - 同步入口：ApiExceptions 转 503 + 友好文案（并加入 dontLogExceptions 免刷日志）
 *   - 异步 TaskJob：识别为可重试 → release 错峰，不标 failed
 */
class MutationBusyException extends RuntimeException
{
    public function __construct(public readonly string $mutexKey = '')
    {
        parent::__construct('该订单正在处理中，请稍后重试');
    }
}
