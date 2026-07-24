<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\ApiResponse;
use Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * 获取缓存时间（分钟）
     */
    private function getCacheMinutes(): int
    {
        return (int) get_system_setting('site', 'dashboardCache', 10);
    }

    /**
     * 获取首页统计数据总览
     */
    public function overview(): void
    {
        $userId = auth('user')->id();
        $user = User::find($userId);

        if (! $user) {
            $this->error('用户不存在');
        }

        $cacheKey = "dashboard:user:$userId:overview";
        $cacheMinutes = $this->getCacheMinutes();

        $data = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($userId, $user) {
            return [
                'user_info' => $user->only(['username', 'email', 'mobile']),
                'assets' => $this->getAssetsData($userId),
                'orders' => $this->getOrdersData($userId),
            ];
        });

        $this->success((array) $data);
    }

    /**
     * 获取资产统计
     */
    public function assets(): void
    {
        $userId = auth('user')->id();

        $cacheKey = "dashboard:user:$userId:assets";
        $cacheMinutes = $this->getCacheMinutes();

        $data = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($userId) {
            return $this->getAssetsData($userId);
        });

        $this->success((array) $data);
    }

    /**
     * 获取订单统计
     */
    public function orders(): void
    {
        $userId = auth('user')->id();

        $cacheKey = "dashboard:user:$userId:orders";
        $cacheMinutes = $this->getCacheMinutes();

        $data = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($userId) {
            return $this->getOrdersData($userId);
        });

        $this->success((array) $data);
    }

    /**
     * 获取趋势数据
     */
    public function trend(Request $request): void
    {
        $userId = auth('user')->id();
        $period = $request->string('period')->toString();
        $period = in_array($period, ['month', 'quarter', 'year'], true) ? $period : 'month';

        $cacheKey = "dashboard:user:$userId:trend:$period";
        $cacheMinutes = $this->getCacheMinutes();

        $trends = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($userId, $period) {
            $now = now();
            $startDate = match ($period) {
                'quarter' => $now->copy()->subWeeks(12)->startOfWeek(),
                'year' => $now->copy()->subMonths(11)->startOfMonth(),
                default => $now->copy()->subDays(29)->startOfDay(),
            };

            $dateExpr = 'DATE(created_at)';
            $dailyRows = Transaction::where('user_id', $userId)
                ->where('created_at', '>=', $startDate)
                ->selectRaw("$dateExpr as date")
                ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END) as orders', Transaction::ORDER_TYPES)
                ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END) as cancelled_orders', Transaction::CANCEL_TYPES)
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN type IN (?, ?, ?, ?, ?, ?) THEN -amount ELSE 0 END), 0) as consumption',
                    ['order', 'cancel', 'deduct', 'reverse', Transaction::TYPE_ACME_ORDER, Transaction::TYPE_ACME_CANCEL]
                )
                ->groupByRaw($dateExpr)
                ->get();

            $trendMap = [];
            foreach ($dailyRows as $row) {
                /** @var object $row */
                $date = Carbon::parse($row->date);
                $bucket = match ($period) {
                    'quarter' => $date->startOfWeek()->format('Y-m-d'),
                    'year' => $date->startOfMonth()->format('Y-m-d'),
                    default => $date->format('Y-m-d'),
                };
                $trendMap[$bucket] ??= [
                    'orders' => 0,
                    'cancelled_orders' => 0,
                    'net_orders' => 0,
                    'consumption' => 0.0,
                ];
                $orders = (int) $row->orders;
                $cancelledOrders = (int) $row->cancelled_orders;
                $trendMap[$bucket]['orders'] += $orders;
                $trendMap[$bucket]['cancelled_orders'] += $cancelledOrders;
                $trendMap[$bucket]['net_orders'] += $orders - $cancelledOrders;
                $trendMap[$bucket]['consumption'] += (float) $row->consumption;
            }

            $trends = [];
            $points = match ($period) {
                'quarter' => 13,
                'year' => 12,
                default => 30,
            };
            for ($i = 0; $i < $points; $i++) {
                $dateStr = match ($period) {
                    'quarter' => $startDate->copy()->addWeeks($i)->format('Y-m-d'),
                    'year' => $startDate->copy()->addMonths($i)->format('Y-m-d'),
                    default => $startDate->copy()->addDays($i)->format('Y-m-d'),
                };
                $bucket = $trendMap[$dateStr] ?? [];
                $trends[] = [
                    'date' => $dateStr,
                    'orders' => (int) ($bucket['orders'] ?? 0),
                    'cancelled_orders' => (int) ($bucket['cancelled_orders'] ?? 0),
                    'net_orders' => (int) ($bucket['net_orders'] ?? 0),
                    'consumption' => round((float) ($bucket['consumption'] ?? 0), 2),
                ];
            }

            return $trends;
        });

        $this->success((array) $trends);
    }

    /**
     * 获取月度统计对比
     */
    public function monthlyComparison(): void
    {
        $userId = auth('user')->id();

        $cacheKey = "dashboard:user:$userId:monthly_comparison";
        $cacheMinutes = $this->getCacheMinutes();

        $comparison = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($userId) {
            $currentMonth = now()->startOfMonth();
            $lastMonth = $currentMonth->copy()->subMonth();

            $monthExpr = "DATE_FORMAT(created_at, '%Y-%m')";
            $orderRows = Transaction::where('user_id', $userId)
                ->where('created_at', '>=', $lastMonth)
                ->selectRaw("$monthExpr as month")
                ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END) as orders', Transaction::ORDER_TYPES)
                ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END) as cancelled_orders', Transaction::CANCEL_TYPES)
                ->groupByRaw($monthExpr)
                ->get()
                ->keyBy('month');

            $consumptionRows = Order::where('user_id', $userId)
                ->where('created_at', '>=', $lastMonth)
                ->selectRaw("$monthExpr as month, COALESCE(SUM(amount), 0) as consumption")
                ->groupByRaw($monthExpr)
                ->pluck('consumption', 'month');

            $currentKey = $currentMonth->format('Y-m');
            $lastKey = $lastMonth->format('Y-m');

            $currentOrders = (int) ($orderRows[$currentKey]->orders ?? 0);
            $currentCancelledOrders = (int) ($orderRows[$currentKey]->cancelled_orders ?? 0);
            $currentNetOrders = $currentOrders - $currentCancelledOrders;
            $currentConsumption = (float) ($consumptionRows[$currentKey] ?? 0);
            $lastOrders = (int) ($orderRows[$lastKey]->orders ?? 0);
            $lastCancelledOrders = (int) ($orderRows[$lastKey]->cancelled_orders ?? 0);
            $lastNetOrders = $lastOrders - $lastCancelledOrders;
            $lastConsumption = (float) ($consumptionRows[$lastKey] ?? 0);

            return [
                'current_month' => [
                    'orders' => $currentOrders,
                    'cancelled_orders' => $currentCancelledOrders,
                    'net_orders' => $currentNetOrders,
                    'consumption' => $currentConsumption,
                ],
                'last_month' => [
                    'orders' => $lastOrders,
                    'cancelled_orders' => $lastCancelledOrders,
                    'net_orders' => $lastNetOrders,
                    'consumption' => $lastConsumption,
                ],
                'growth' => [
                    'orders' => $this->calculateGrowth($lastOrders, $currentOrders),
                    'cancelled_orders' => $this->calculateGrowth($lastCancelledOrders, $currentCancelledOrders),
                    'net_orders' => $this->calculateGrowth($lastNetOrders, $currentNetOrders),
                    'consumption' => $this->calculateGrowth($lastConsumption, $currentConsumption),
                ],
            ];
        });

        $this->success((array) $comparison);
    }

    /**
     * 获取资产数据
     */
    private function getAssetsData($userId): array
    {
        $balance = User::where('id', $userId)->value('balance');

        return [
            'balance' => (float) ($balance ?? 0),
        ];
    }

    /**
     * 获取订单数据（包含订单状态分布）
     */
    private function getOrdersData($userId): array
    {
        $now = now();
        $in7Days = $now->copy()->addDays(7);
        $in30Days = $now->copy()->addDays(30);
        $monthStart = $now->copy()->startOfMonth();

        // 单次 JOIN 查询：状态分布 + active/到期统计（条件聚合）
        $certStats = Order::where('orders.user_id', $userId)
            ->join('certs', 'orders.latest_cert_id', '=', 'certs.id')
            ->selectRaw("certs.status, COUNT(*) as count,
                SUM(CASE WHEN certs.status = 'active' AND certs.expires_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_7,
                SUM(CASE WHEN certs.status = 'active' AND certs.expires_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as expiring_30",
                [$now, $in7Days, $now, $in30Days])
            ->groupBy('certs.status')
            ->get();

        $statusDistribution = [];
        $orderCount = 0;
        $activeOrders = 0;
        $processingOrders = 0;
        $expiring7Days = 0;
        $expiring30Days = 0;

        foreach ($certStats as $row) {
            /** @var object $row */
            $statusDistribution[$row->status] = (int) $row->count;
            $orderCount += (int) $row->count;
            if (in_array($row->status, ['unpaid', 'pending', 'processing', 'approving'], true)) {
                $processingOrders += (int) $row->count;
            }
            if ($row->status === 'active') {
                $activeOrders = (int) $row->count;
                $expiring7Days = (int) $row->expiring_7;
                $expiring30Days = (int) $row->expiring_30;
            }
        }

        /** @var object $orderStats */
        $orderStats = Transaction::where('user_id', $userId)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END) as total', Transaction::ORDER_TYPES)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN 1 ELSE 0 END) as cancelled', Transaction::CANCEL_TYPES)
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND type IN (?, ?) THEN 1 ELSE 0 END) as monthly_orders', [$monthStart, ...Transaction::ORDER_TYPES])
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND type IN (?, ?) THEN 1 ELSE 0 END) as monthly_cancelled', [$monthStart, ...Transaction::CANCEL_TYPES])
            ->first();

        $totalOrders = (int) ($orderStats->total ?? 0);
        $cancelledOrders = (int) ($orderStats->cancelled ?? 0);
        $monthlyOrders = (int) ($orderStats->monthly_orders ?? 0);
        $monthlyCancelledOrders = (int) ($orderStats->monthly_cancelled ?? 0);
        $monthlyConsumption = (float) Order::where('user_id', $userId)
            ->where('created_at', '>=', $monthStart)
            ->sum('amount');

        $brandDistribution = Order::where('user_id', $userId)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->selectRaw('LOWER(brand) as brand, COUNT(*) as count')
            ->groupByRaw('LOWER(brand)')
            ->orderByDesc('count')
            ->pluck('count', 'brand')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'total_orders' => $totalOrders,
            'order_count' => $orderCount,
            'active_orders' => $activeOrders,
            'processing_orders' => $processingOrders,
            'expiring_7_days' => $expiring7Days,
            'expiring_30_days' => $expiring30Days,
            'cancelled_orders' => $cancelledOrders,
            'net_orders' => $totalOrders - $cancelledOrders,
            'status_distribution' => $statusDistribution,
            'brand_distribution' => $brandDistribution,
            'monthly_orders' => $monthlyOrders,
            'monthly_cancelled_orders' => $monthlyCancelledOrders,
            'monthly_net_orders' => $monthlyOrders - $monthlyCancelledOrders,
            'monthly_consumption' => $monthlyConsumption,
        ];
    }

    /**
     * 计算增长率
     */
    private function calculateGrowth($lastValue, $currentValue): float
    {
        if ($lastValue == 0) {
            return $currentValue > 0 ? 100 : 0;
        }

        return round((($currentValue - $lastValue) / $lastValue) * 100, 2);
    }
}
