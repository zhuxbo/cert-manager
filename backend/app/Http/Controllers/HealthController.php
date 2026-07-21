<?php

namespace App\Http\Controllers;

use App\Utils\UpgradeFreezeLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 公开运维健康检查
 *
 * 路由 GET /api/health（命名空间无关），供安装等待、升级 smoke test、
 * 管理后台健康度及可选外部健康检查使用。不鉴权、不写日志、不受 MaintenanceMode 拦截。
 *
 * 与现有 /api/v1/health、/api/v2/health 区别：现有两个端点是 API 业务接口
 * （挂在 v1/v2 命名空间下，未来 v3 可能改），本端点是命名空间无关的运维标准入口。
 */
class HealthController extends Controller
{
    /**
     * 健康检查入口
     *
     * 返回结构：
     * {
     *   "status": "ok" | "degraded" | "error",
     *   "freeze": bool,
     *   "checks": {
     *     "db": { "ok": bool, "latency_ms": int },
     *     "cache": { "ok": bool },
     *     "queue_lag_seconds": int,
     *     "disk_free_gb": float,
     *     "heartbeat_age_seconds": int|null
     *   },
     *   "check_statuses": {
     *     "db": "ok" | "degraded" | "error",
     *     "cache": "ok" | "degraded" | "error",
     *     "heartbeat": "ok" | "degraded" | "error",
     *     "queue": "ok" | "degraded" | "error",
     *     "disk": "ok" | "degraded" | "error"
     *   },
     *   "queue_lag_unit": "seconds" | "jobs"
     * }
     *
     * HTTP 状态码：error → 503；ok / degraded → 200。
     * - error（db 挂 / cache 后端故障 / 磁盘不足 / queue_lag 超阈 / 心跳过旧 stale）→ 503。
     * - degraded（心跳键缺失：新装机未跑调度 / cache:clear 清键）→ 200（不 503，避免误报）。
     * freeze 期间 queue_lag_seconds 与心跳 stale 均不参与 503 判定（worker/scheduler 已按升级流程停止）。
     */
    public function index(): JsonResponse
    {
        $freeze = UpgradeFreezeLock::isFrozen();

        $checks = [
            'db' => $this->dbCheck(),
            'cache' => $this->cacheCheck(),
            'queue_lag_seconds' => $this->queueLag(),
            'disk_free_gb' => $this->diskFree(),
            'heartbeat_age_seconds' => $this->heartbeatAge(),
        ];

        $status = $this->aggregate($checks, $freeze);
        $checkStatuses = $this->checkStatuses($checks, $freeze);
        // 仅 error → 503；degraded（心跳缺失）与 ok 均 200：
        // 新装机/cache:clear 后心跳键尚未播种，判 degraded 而非 stale 503，便于后台准确展示状态。
        $httpStatus = $status === 'error'
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;

        return new JsonResponse([
            'status' => $status,
            'freeze' => $freeze,
            'checks' => $checks,
            'check_statuses' => $checkStatuses,
            'queue_lag_unit' => config('queue.default') === 'redis' ? 'jobs' : 'seconds',
        ], $httpStatus);
    }

    /**
     * 逐项状态供管理后台着色；不改变 aggregate() 的整体健康判定。
     *
     * freeze 期间队列积压和心跳过旧是升级流程的预期现象，显示 degraded 而非 error。
     * cache 故障时无法可靠读取可配置阈值，其余依赖阈值的项目显示 degraded。
     *
     * @param  array{db: array{ok: bool, latency_ms: int}, cache: array{ok: bool}, queue_lag_seconds: int, disk_free_gb: float, heartbeat_age_seconds: int|null}  $checks
     * @return array{db: string, cache: string, heartbeat: string, queue: string, disk: string}
     */
    protected function checkStatuses(array $checks, bool $freeze): array
    {
        $statuses = [
            'db' => $checks['db']['ok'] === true ? 'ok' : 'error',
            'cache' => $checks['cache']['ok'] === true ? 'ok' : 'error',
            'heartbeat' => 'degraded',
            'queue' => 'degraded',
            'disk' => 'degraded',
        ];

        if ($checks['db']['ok'] !== true || $checks['cache']['ok'] !== true) {
            return $statuses;
        }

        $statuses['disk'] = $checks['disk_free_gb'] < (float) get_system_setting(
            'health',
            'disk_free_threshold_gb',
            1.0
        ) ? 'error' : 'ok';

        $queueExceeded = $checks['queue_lag_seconds'] > $this->queueThreshold();
        $statuses['queue'] = $queueExceeded ? ($freeze ? 'degraded' : 'error') : 'ok';

        if ($checks['heartbeat_age_seconds'] !== null) {
            $heartbeatStale = $checks['heartbeat_age_seconds'] > (int) get_system_setting(
                'health',
                'heartbeat_stale_seconds',
                300
            );
            $statuses['heartbeat'] = $heartbeatStale ? ($freeze ? 'degraded' : 'error') : 'ok';
        }

        return $statuses;
    }

    /**
     * DB 连接探活
     *
     * 不抛异常；失败时 ok=false / latency_ms=0。
     *
     * @return array{ok: bool, latency_ms: int}
     */
    protected function dbCheck(): array
    {
        $start = hrtime(true);

        try {
            DB::connection()->getPdo();
            $elapsed = (int) round((hrtime(true) - $start) / 1_000_000);

            return ['ok' => true, 'latency_ms' => $elapsed];
        } catch (Throwable) {
            return ['ok' => false, 'latency_ms' => 0];
        }
    }

    /**
     * Cache 后端探活
     *
     * 心跳年龄（heartbeatAge）与 health 阈值（aggregate/queueThreshold 经 get_system_setting →
     * Cache::remember）均依赖 Cache（driver=redis 时）。Cache 后端故障绝不能让 /api/health 白屏
     * 500 丢弃结构化输出——须显式探活并结构化上报 error（503）。用只读 get 探连通性（不写键，
     * 避免后台刷新或外部监控访问时频繁写 cache）；不抛异常，失败 ok=false。
     *
     * @return array{ok: bool}
     */
    protected function cacheCheck(): array
    {
        try {
            Cache::get('schedule:heartbeat');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }

    /**
     * 计算队列积压秒数
     *
     * 按 config('queue.default') 分发：
     * - database：min(jobs.available_at where reserved_at IS NULL) 后 PHP 计算 time() - $min
     * - redis：全队列（queue.names）就绪深度 + 已到期延时深度求和（见 queueLagRedis()）
     * - sync / 其他：返回 0
     *
     * 任何失败一律返回 0（健康检查不应因 queue 探活异常而 503）。
     */
    protected function queueLag(): int
    {
        $driver = config('queue.default');

        try {
            return match ($driver) {
                'database' => $this->queueLagDatabase(),
                'redis' => $this->queueLagRedis(),
                default => 0,
            };
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * database driver 的 queue lag
     *
     * available_at 是 Unix 秒整数；min() 后 PHP 计算 time() - $min。
     * 没有待处理 Job 时返回 0。
     */
    protected function queueLagDatabase(): int
    {
        $minAvailableAt = DB::table('jobs')
            ->whereNull('reserved_at')
            ->min('available_at');

        if ($minAvailableAt === null) {
            return 0;
        }

        return max(0, time() - (int) $minAvailableAt);
    }

    /**
     * redis driver 的 queue lag（返回队列深度，条数）
     *
     * 遍历 config('queue.names') 的全部队列名（notifications/tasks/default），每队列求和：
     *  - 就绪深度：llen queues:{name}
     *  - 已到期延时：zcount queues:{name}:delayed -inf now —— 只计 score ≤ now 的到期部分。
     *    worker 死亡时到期 job 无人搬运即堆积；整包 zcard 会把 auto-renew 夜间 0~8h 延时
     *    commit 批次（未到期 score 在未来）当积压，导致 00:00-08:00 持续误报，故只计已到期。
     *  - 不计 :reserved zset（在途健康工作非积压）。
     *
     * 含 default 与 queueLagDatabase 全队列扫描语义对称：default 按约定恒空（全仓无 Job 派 default），
     * 求和加 0 无害；若非空即真积压（漏写 onQueue 的 Job）应报，非假 lag。
     * 裸 queues:{name} 键式（facade 自动套连接 prefix，与队列写入端对称）；外层 catch 兜底返 0。
     */
    protected function queueLagRedis(): int
    {
        $now = time();
        $total = 0;

        $names = array_unique(array_values(config('queue.names', ['default' => 'default'])));
        foreach ($names as $name) {
            $total += (int) Redis::command('llen', ["queues:$name"]);
            $total += (int) Redis::command('zcount', ["queues:$name:delayed", '-inf', $now]);
        }

        return max(0, $total);
    }

    /**
     * 调度器心跳年龄（秒）
     *
     * schedule:heartbeat 命令每分钟 Cache::forever('schedule:heartbeat', now()->timestamp)。
     * - 键缺失（null）→ 返回 null：新装机未跑过调度 / cache:clear 清键，aggregate 判 degraded 非 stale。
     * - 键存在 → time() - 存储时间戳（下限 0，防时钟回拨出负值）。
     *
     * 用 forever 无 TTL 是刻意选型：死 scheduler 留旧时间戳 → age 超阈 → stale 503（正确）；
     * 带 TTL 则键到期消失 → 缺失 → degraded 200，会把死 scheduler 误判为「未装机」。
     */
    protected function heartbeatAge(): ?int
    {
        try {
            $stored = Cache::get('schedule:heartbeat');
        } catch (Throwable) {
            // Cache 后端故障：cacheCheck 已判 error（503），此处返 null 不参与 degraded
            // （aggregate 的 cache error 分支先于 degraded return，故不会被误判 degraded 200）。
            return null;
        }

        if ($stored === null) {
            return null;
        }

        return max(0, time() - (int) $stored);
    }

    /**
     * 磁盘剩余空间（GB，保留 1 位小数）
     *
     * 检查 storage_path() 所在卷；获取失败返回 0.0。
     */
    protected function diskFree(): float
    {
        $bytes = @disk_free_space(storage_path());
        if ($bytes === false) {
            return 0.0;
        }

        return round($bytes / (1024 ** 3), 1);
    }

    /**
     * 综合判定
     *
     * 判定序固化：所有 error 分支必须全部先于 degraded 分支 return，否则「心跳缺失 → degraded 200」
     * 会掩盖真错误（如 db 挂时误判 200）。
     * - ① DB ping 失败 → error（503）
     * - ② cache 后端故障 → error（503）——必须先于下方任何 get_system_setting（其读取经
     *      Cache::remember，cache 故障时会抛异常）
     * - ③ disk_free_gb < 阈值 → error（503）
     * - ④ freeze=false 时：queue_lag 超阈 → error；心跳存在且过旧（stale）→ error
     * - ⑤ 心跳缺失（null）→ degraded（200）——排在全部 error 检查之后
     * - ⑥ 其他 → ok（200）
     *
     * freeze=true 时 queue_lag 与心跳 stale 均不参与 503 判定（worker/scheduler 已按升级流程停止）；
     * cache 后端故障不受 freeze 豁免（cache 是独立于升级流程的基础设施）。
     *
     * @param  array{db: array{ok: bool, latency_ms: int}, cache: array{ok: bool}, queue_lag_seconds: int, disk_free_gb: float, heartbeat_age_seconds: int|null}  $checks
     */
    protected function aggregate(array $checks, bool $freeze): string
    {
        if ($checks['db']['ok'] !== true) {
            return 'error';
        }

        // Cache 后端故障 → error（503）。必须早于下方 get_system_setting（disk/queue/heartbeat 阈值
        // 读取经 Cache::remember，cache 故障时会抛），且早于 degraded 分支（error 先于 degraded 红线）。
        if ($checks['cache']['ok'] !== true) {
            return 'error';
        }

        $diskThreshold = (float) get_system_setting('health', 'disk_free_threshold_gb', 1.0);
        if ($checks['disk_free_gb'] < $diskThreshold) {
            return 'error';
        }

        if (! $freeze) {
            if ($checks['queue_lag_seconds'] > $this->queueThreshold()) {
                return 'error';
            }

            // 心跳存在且过旧 → stale 503（死 scheduler）。缺失（null）不在此判，留待下方 degraded。
            $staleThreshold = (int) get_system_setting('health', 'heartbeat_stale_seconds', 300);
            if ($checks['heartbeat_age_seconds'] !== null
                && $checks['heartbeat_age_seconds'] > $staleThreshold) {
                return 'error';
            }
        }

        // 心跳缺失（null）→ degraded：必须排在全部 error 检查之后（防真错误被 200 掩盖）。
        if ($checks['heartbeat_age_seconds'] === null) {
            return 'degraded';
        }

        return 'ok';
    }

    /**
     * queue lag 判定阈值（按驱动取义，消除「秒 vs 深度条数」两义）
     *
     * - redis：queueLagRedis 返回队列深度（条数），用 queue_depth_threshold（默认 500 条）。
     * - 其余（database）：queueLagDatabase 返回积压秒数，用 queue_lag_threshold（默认 600 秒，语义不变）。
     *
     * 低量 redis 部署若沿用 600「秒」阈值当深度门槛，需堆 600 条才 503 → worker 死检测显著延迟。
     */
    protected function queueThreshold(): int
    {
        if (config('queue.default') === 'redis') {
            return (int) get_system_setting('health', 'queue_depth_threshold', 500);
        }

        return (int) get_system_setting('health', 'queue_lag_threshold', 600);
    }
}
