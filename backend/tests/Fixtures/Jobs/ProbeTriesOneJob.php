<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 复现夹具：tries=1 + 升级冻结中间件（与受影响的真实 Job 同构）。
 *
 * 仅供 UpgradeFreezeReleaseAttemptsTest 验证 Laravel 13 的 attempts 语义。
 * handle 执行次数记在静态 $ran：worker 在测试同进程内运行，可直接读取。
 * 放在 tests/ 下、不在 app/Jobs/，故不被主系统反射守门扫描。
 */
class ProbeTriesOneJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public static int $ran = 0;

    public int $tries = 1;

    public function handle(): void
    {
        self::$ran++;
    }
}
