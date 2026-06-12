<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * 复现夹具：tries=5 + maxExceptions=1 + 升级冻结中间件（修复后的目标配置）。
 *
 * 验证两点：
 *  - freeze release 多次不再误杀（tries=5 吸收 attempts 累加）
 *  - 业务异常仍只允许一次（$throw=true 时 handle 抛，maxExceptions=1 第一次即失败、不重试）
 */
class ProbeTriesFiveJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public static int $ran = 0;

    public static bool $throw = false;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public function handle(): void
    {
        self::$ran++;

        if (self::$throw) {
            throw new RuntimeException('probe-business-failure');
        }
    }
}
