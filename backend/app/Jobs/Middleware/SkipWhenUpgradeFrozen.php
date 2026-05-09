<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Utils\UpgradeFreezeLock;
use Closure;

/**
 * Queue middleware：升级 freeze 期间跳过 Job 业务。
 *
 * 升级冻结锁存在时，Job 不执行业务逻辑：
 *  - 调 $job->release(60) 把任务放回队列，60 秒后再让 worker 重新尝试
 *  - 不调 $next($job)，确保业务方法不被触发
 *
 * 与"进程级停止 worker"组合形成双重保险（队列暂停机制）。
 *
 * 注意：Laravel Queue middleware 的 $job 参数是 Job 实例，
 * Job 通过 InteractsWithQueue trait 提供 release($delay) 方法。
 */
class SkipWhenUpgradeFrozen
{
    /**
     * 业务跳过后让 worker 重新尝试的延迟（秒）。
     */
    public const int RELEASE_DELAY_SECONDS = 60;

    /**
     * 处理 Job。
     *
     * @param  object  $job  Job 实例（含 InteractsWithQueue trait）
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (UpgradeFreezeLock::isFrozen()) {
            // freeze 期间不执行业务，把任务放回队列稍后再试
            if (method_exists($job, 'release')) {
                $job->release(self::RELEASE_DELAY_SECONDS);
            }

            return null;
        }

        return $next($job);
    }
}
