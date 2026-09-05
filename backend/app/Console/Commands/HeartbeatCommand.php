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
 * 单一职责：把 schedule:heartbeat 写入 runtime store，供 /api/health 判活。
 *
 * 用 forever 无 TTL 是刻意选型：
 *  - 死 scheduler 留旧时间戳 → /api/health 判 age 超阈 → stale 503（正确检出）。
 *  - 带 TTL 则键到期消失 → 缺失 → degraded 200，会把死 scheduler 误判为「未装机」。
 *  - 安全清理不删除 runtime 键；首次安装尚未播种时 → 缺失 → degraded 200。
 *
 * 访问时检测边界（文档化于 skills/ops/deploy-ops.md）：首次安装尚未运行 scheduler
 * → 键缺失 → degraded 200，后台健康度显示“需要关注”，不会主动发信。
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'schedule:heartbeat';

    protected $description = '调度器心跳（写 runtime store，供 /api/health 判活）';

    public function handle(): int
    {
        Cache::store('runtime')->forever('schedule:heartbeat', now()->timestamp);

        return self::SUCCESS;
    }
}
