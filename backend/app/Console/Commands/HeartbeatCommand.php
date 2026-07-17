<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 调度器心跳（P0-4.1）。
 *
 * 调度：schedule:heartbeat，每分钟一次。与 upgrade:watchdog 同为有意 freeze 存活者
 * （evenInMaintenanceMode + 不挂 skip($skipWhenFrozen)）——见 routes/console.php 注释。
 *
 * 单一职责：写 Cache::forever('schedule:heartbeat', now()->timestamp)，供 /api/health 判活。
 *
 * 用 forever 无 TTL 是刻意选型：
 *  - 死 scheduler 留旧时间戳 → /api/health 判 age 超阈 → stale 503（正确检出）。
 *  - 带 TTL 则键到期消失 → 缺失 → degraded 200，会把死 scheduler 误判为「未装机」。
 *  - cache:clear 清键 → 缺失 → degraded 200（新装机/清缓存不 503）。
 *
 * F1 死角（文档化于 skills/ops/deploy-ops.md）：「已死 scheduler + 之后 cache:clear」→ 键缺失
 * → degraded 200 → 拨测静默。这是 forever+missing→degraded 换取「新装机不 503」的固有对价；
 * 兜底 = 宝塔站点外部监控（部署必选项，无视本机 cache 状态）。
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'schedule:heartbeat';

    protected $description = '调度器心跳（写 Cache，供 /api/health 判活）';

    public function handle(): int
    {
        Cache::forever('schedule:heartbeat', now()->timestamp);

        return self::SUCCESS;
    }
}
