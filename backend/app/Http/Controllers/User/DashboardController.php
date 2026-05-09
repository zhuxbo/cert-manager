<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Traits\ApiResponse;
use Cache;
use Illuminate\Http\Request;

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
        $days = min(max($request->input('days', 30), 7), 90);

        $cacheKey = "dashboard:user:$userId:trend:$days";
        $cacheMinutes = $this->getCacheMinutes();

        $trends = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($userId, $days) {
            $startDate = now()->subDays($days - 1)->startOfDay();

            $dateExpr = 'DATE(created_at)';
            $rows = Order::where('user_id', $userId)
                ->where('created_at', '>=', $startDate)
                ->selectRaw("$dateExpr as date, COUNT(*) as orders, COALESCE(SUM(amount), 0) as consumption")
                ->groupByRaw($dateExpr)
                ->get()
                ->keyBy('date');

            $trends = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $dateStr = now()->subDays($i)->format('Y-m-d');
                /** @var object|null $row */
                $row = $rows[$dateStr] ?? null;
                $trends[] = [
                    'date' => $dateStr,
                    'orders' => $row ? (int) $row->orders : 0,
                    'consumption' => $row ? (float) $row->consumption : 0,
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
            $rows = Order::where('user_id', $userId)
                ->where('created_at', '>=', $lastMonth)
                ->selectRaw("$monthExpr as month, COUNT(*) as orders, COALESCE(SUM(amount), 0) as consumption")
                ->groupByRaw($monthExpr)
                ->get()
                ->keyBy('month');

            $currentKey = $currentMonth->format('Y-m');
            $lastKey = $lastMonth->format('Y-m');

            $currentOrders = (int) ($rows[$currentKey]->orders ?? 0);
            $currentConsumption = (float) ($rows[$currentKey]->consumption ?? 0);
            $lastOrders = (int) ($rows[$lastKey]->orders ?? 0);
            $lastConsumption = (float) ($rows[$lastKey]->consumption ?? 0);

            return [
                'current_month' => [
                    'orders' => $currentOrders,
                    'consumption' => $currentConsumption,
                ],
                'last_month' => [
                    'orders' => $lastOrders,
                    'consumption' => $lastConsumption,
                ],
                'growth' => [
                    'orders' => $this->calculateGrowth($lastOrders, $currentOrders),
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
        $activeOrders = 0;
        $expiring7Days = 0;
        $expiring30Days = 0;

        foreach ($certStats as $row) {
            /** @var object $row */
            $statusDistribution[$row->status] = (int) $row->count;
            if ($row->status === 'active') {
                $activeOrders = (int) $row->count;
                $expiring7Days = (int) $row->expiring_7;
                $expiring30Days = (int) $row->expiring_30;
            }
        }

        // 单次查询：总数 + 取消数 + 本月统计
        /** @var object $orderStats */
        $orderStats = Order::where('user_id', $userId)
            ->selectRaw('COUNT(*) as total,
                SUM(CASE WHEN cancelled_at IS NOT NULL THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as monthly_orders,
                SUM(CASE WHEN created_at >= ? THEN amount ELSE 0 END) as monthly_consumption',
                [$monthStart, $monthStart])
            ->first();

        return [
            'total_orders' => (int) $orderStats->total,
            'active_orders' => $activeOrders,
            'expiring_7_days' => $expiring7Days,
            'expiring_30_days' => $expiring30Days,
            'cancelled_orders' => (int) $orderStats->cancelled,
            'status_distribution' => $statusDistribution,
            'monthly_orders' => (int) $orderStats->monthly_orders,
            'monthly_consumption' => (float) $orderStats->monthly_consumption,
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
