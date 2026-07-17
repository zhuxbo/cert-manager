<?php

declare(strict_types=1);

namespace App\Support;

use App\Bootstrap\ApiExceptions;
use App\Exceptions\MutationBusyException;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 订单级互斥原语：commit/cancel 进 DB 行锁前先抢一把按订单 id 的 Cache 互斥锁，
 * 把「DB 锁等待 innodb_lock_wait_timeout(50s) → 1205」转化为「Cache 抢锁立即失败」。
 *
 * 详见 .superpowers/specs 方案 C / CLAUDE.md「资金/状态变更必须在事务+行锁内」：
 *   - 抢到 → 执行闭包，finally 原子释放（Cache::lock 带 owner token，TTL 过期后不误删他人锁）
 *   - 抢不到 → 抛 MutationBusyException（同步转 503 友好提示 / 异步 TaskJob release 重试）
 *   - Cache 故障 → fail-open 放行（退回 DB 锁串行，由 Sdk 锁内超时兜底；慢但不 1205、不阻塞业务）
 *
 * 用非阻塞 ->get()（而非 ->block()）：抢不到立即返回，绝不在 Cache 层排队等待。
 */
trait MutexLock
{
    protected function withMutex(string $key, Closure $callback, int $ttl = 60): mixed
    {
        try {
            $lock = Cache::lock($key, $ttl);
            $acquired = $lock->get();
        } catch (Throwable $e) {
            // Cache 故障 fail-open：与 ActionTrait::checkDuplicate 同向，放行不阻塞业务
            app(ApiExceptions::class)->logException($e);

            return $callback();
        }

        if (! $acquired) {
            throw new MutationBusyException($key);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
