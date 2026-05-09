<?php

namespace App\Http\Controllers;

use App\Utils\UpgradeFreezeLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 公开运维健康检查
 *
 * 路由 GET /api/health（命名空间无关），供安装等待、升级 smoke test、
 * 外部健康检查使用。不鉴权、不写日志、不受 MaintenanceMode 拦截。
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
     *     "queue_lag_seconds": int,
     *     "disk_free_gb": float
     *   }
     * }
     *
     * HTTP 状态码：所有检查通过 200；任一关键检查失败 503。
     * freeze 期间 queue_lag_seconds 不参与 503 判定（worker 已按升级流程停止）。
     */
    public function index(): JsonResponse
    {
        $freeze = UpgradeFreezeLock::isFrozen();

        $checks = [
            'db' => $this->dbCheck(),
            'queue_lag_seconds' => $this->queueLag(),
            'disk_free_gb' => $this->diskFree(),
        ];

        $status = $this->aggregate($checks, $freeze);
        $httpStatus = $status === 'ok'
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $status,
            'freeze' => $freeze,
            'checks' => $checks,
        ], $httpStatus);
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
     * 计算队列积压秒数
     *
     * 按 config('queue.default') 分发：
     * - database：min(jobs.available_at where reserved_at IS NULL) 后 PHP 计算 time() - $min
     * - redis：第一版只返回队列深度（Redis::llen("queues:default")），不计算时间差
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
     * redis driver 的 queue lag
     *
     * 第一版只返回 default 队列深度。如需精确 lag，后续把 enqueue timestamp 写入
     * payload/tag，不在本次实现里扩展。
     */
    protected function queueLagRedis(): int
    {
        $depth = Redis::llen('queues:default');

        return max(0, (int) $depth);
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
     * - DB ping 失败 → error（503）
     * - disk_free_gb < 阈值 → error（503）
     * - queue_lag_seconds > 阈值 且 freeze=false → error（503）
     * - freeze=true 时 queue_lag 不参与 503 判定（worker 已按升级流程停止）
     * - 其他 → ok（200）
     *
     * @param  array{db: array{ok: bool, latency_ms: int}, queue_lag_seconds: int, disk_free_gb: float}  $checks
     */
    protected function aggregate(array $checks, bool $freeze): string
    {
        if ($checks['db']['ok'] !== true) {
            return 'error';
        }

        $diskThreshold = (float) get_system_setting('health', 'disk_free_threshold_gb', 1.0);
        if ($checks['disk_free_gb'] < $diskThreshold) {
            return 'error';
        }

        if (! $freeze) {
            $lagThreshold = (int) get_system_setting('health', 'queue_lag_threshold', 600);
            if ($checks['queue_lag_seconds'] > $lagThreshold) {
                return 'error';
            }
        }

        return 'ok';
    }
}
