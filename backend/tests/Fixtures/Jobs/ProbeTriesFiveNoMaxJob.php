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
 * 复现夹具：tries=5 且【不声明 maxExceptions】+ 升级冻结中间件。
 *
 * 对齐 C5 对 TaskJob / SubmitDocumentJob 的实际配置（tries=5 且 maxExceptions=null，
 * 与带 maxExceptions=1 的 ProbeTriesFiveJob 互补）。验证「tries=5 且无 maxExceptions」
 * 组合在 freeze 期经多次 release 仍存活、解冻后 handle 恰跑一次，闭合经验覆盖缺口。
 */
class ProbeTriesFiveNoMaxJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public static int $ran = 0;

    public int $tries = 5;

    public function handle(): void
    {
        self::$ran++;
    }
}
