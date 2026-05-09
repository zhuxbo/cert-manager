<?php

namespace App\Http\Controllers\Admin;

use App\Models\CaLog;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 系统运维指标端点。
 *
 * 提供 admin 后台"系统状态"页面消费的 6 类指标：orders / queue / ca / logs / database / collected_at。
 * 端点只返回数据，不返回 status，不参与 503 判定（503 由 /api/health 负责）。
 */
class MetricsController extends BaseController
{
    use ApiResponse;

    public function index(): void
    {
        $this->success([
            'orders' => $this->orders(),
            'queue' => $this->queue(),
            'ca' => $this->ca(),
            'logs' => $this->logs(),
            'database' => $this->database(),
            'collected_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 订单 24h / 7d 总单量 + 按 latestCert.status 分布。
     */
    private function orders(): array
    {
        $now = now();
        $h24 = $now->copy()->subDay();
        $d7 = $now->copy()->subDays(7);

        $count24h = Order::where('created_at', '>=', $h24)->count();
        $count7d = Order::where('created_at', '>=', $d7)->count();

        // 状态分布走 latestCert.status（订单本身不存状态）
        $statusRows = DB::table('orders')
            ->join('certs', 'orders.latest_cert_id', '=', 'certs.id')
            ->selectRaw('certs.status as status, COUNT(*) as cnt')
            ->groupBy('certs.status')
            ->get();

        $statusDistribution = [];
        foreach ($statusRows as $row) {
            $statusDistribution[(string) $row->status] = (int) $row->cnt;
        }

        return [
            'count_24h' => $count24h,
            'count_7d' => $count7d,
            'status_distribution' => $statusDistribution,
        ];
    }

    /**
     * 队列深度（database driver）：jobs 表行数 + failed_jobs 行数 + 当前最大滞后秒数。
     *
     * jobs.available_at 为 unix timestamp，lag = max(0, now - min(available_at))。
     * 非 database driver 下 jobs/failed_jobs 表可能不存在，统一返回 0 / null。
     */
    private function queue(): array
    {
        $jobs = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        $lagSeconds = 0;
        if ($jobs > 0) {
            $minAvailable = DB::table('jobs')->min('available_at');
            if ($minAvailable !== null) {
                $lagSeconds = max(0, time() - (int) $minAvailable);
            }
        }

        return [
            'jobs' => $jobs,
            'failed_jobs' => $failed,
            'lag_seconds' => $lagSeconds,
        ];
    }

    /**
     * CA 出站调用：最近 24h 总数 / 成功率 / 延迟 P50 / P95（基于 ca_logs.duration 秒列）。
     *
     * P50/P95 在 PHP 端计算（数据量预期 < 10k）。
     */
    private function ca(): array
    {
        $since = now()->subDay();

        $stats = DB::table('ca_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as success')
            ->first();

        $total = (int) ($stats->total ?? 0);
        $success = (int) ($stats->success ?? 0);
        $successRate = $total > 0 ? round($success / $total * 100, 2) : 0.0;

        $p50Ms = 0;
        $p95Ms = 0;

        if ($total > 0) {
            // duration 单位为秒（DECIMAL），对外统一暴露毫秒
            $durations = CaLog::where('created_at', '>=', $since)
                ->orderBy('duration')
                ->pluck('duration')
                ->map(fn ($v) => (float) $v)
                ->all();

            $count = count($durations);
            if ($count > 0) {
                $p50Index = (int) floor($count * 0.5);
                $p95Index = (int) floor($count * 0.95);
                if ($p50Index >= $count) {
                    $p50Index = $count - 1;
                }
                if ($p95Index >= $count) {
                    $p95Index = $count - 1;
                }
                $p50Ms = (int) round($durations[$p50Index] * 1000);
                $p95Ms = (int) round($durations[$p95Index] * 1000);
            }
        }

        return [
            'requests_24h' => $total,
            'success_rate' => $successRate,
            'latency_p50_ms' => $p50Ms,
            'latency_p95_ms' => $p95Ms,
        ];
    }

    /**
     * 6 张日志表 + tasks / notifications 行数（行数即可，体积由 database 块给出）。
     */
    private function logs(): array
    {
        $tables = [
            'admin_logs',
            'user_logs',
            'api_logs',
            'callback_logs',
            'ca_logs',
            'error_logs',
            'tasks',
            'notifications',
        ];

        $result = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $result[$table] = ['row_count' => 0, 'size_bytes' => null];

                continue;
            }
            $result[$table] = [
                'row_count' => (int) DB::table($table)->count(),
                'size_bytes' => $this->tableSizeBytes($table),
            ];
        }

        return $result;
    }

    /**
     * 单表占用字节数（mysql information_schema.TABLES 查 data + index 大小）。
     */
    private function tableSizeBytes(string $table): ?int
    {
        $row = DB::selectOne(
            'SELECT (data_length + index_length) AS bytes
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return $row && $row->bytes !== null ? (int) $row->bytes : null;
    }

    /**
     * 数据库总大小（mysql information_schema.TABLES 求和）。
     */
    private function database(): array
    {
        $row = DB::selectOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
             FROM information_schema.tables
             WHERE table_schema = DATABASE()'
        );
        $totalBytes = $row ? (int) $row->bytes : 0;

        return [
            'driver' => DB::connection()->getDriverName(),
            'total_size_bytes' => $totalBytes,
            'total_size_mb' => round($totalBytes / 1024 / 1024, 2),
        ];
    }
}
