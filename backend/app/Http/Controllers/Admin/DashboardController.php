<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiLog;
use App\Models\Order;
use App\Models\User;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * 获取管理端首页统计数据总览
     */
    public function overview(): void
    {
        $cacheKey = 'dashboard:admin:overview';
        $cacheMinutes = $this->getCacheMinutes();

        $data = Cache::remember($cacheKey, $cacheMinutes * 60, function () {
            return [
                'total_users' => User::count(),
                'total_orders' => Order::count(),
                'total_revenue' => Order::sum('amount'),
                'active_orders' => $this->getActiveOrdersCount(),
            ];
        });

        $this->success((array) $data);
    }

    /**
     * 获取系统概览统计
     */
    public function systemOverview(): void
    {
        $cacheKey = 'dashboard:admin:system_overview';
        $cacheMinutes = $this->getCacheMinutes();

        $data = Cache::remember($cacheKey, $cacheMinutes * 60, function () {
            $today = now()->startOfDay();
            $thisMonth = now()->startOfMonth();
            $thisWeekMonday = now()->startOfWeek();

            // 月度数据
            $monthlyData = [
                'total_users' => User::count(),
                'total_orders' => Order::count(),
                'active_orders' => $this->getActiveOrdersCount(),
                'expiring_orders' => Order::join('certs', 'orders.latest_cert_id', '=', 'certs.id')
                    ->where('certs.status', 'active')
                    ->whereBetween('certs.expires_at', [now(), now()->addDays(30)])
                    ->count(),
            ];

            // 获取今日API统计
            $todayApiStats = $this->getTodayApiStats();

            // 财务数据：日/周/月充值和消费
            $financeData = $this->getFinanceData($today, $thisWeekMonday, $thisMonth);

            // 新增用户统计：日/周/月（一条 SQL）
            // 跨月周场景下 $thisWeekMonday 可能早于 $thisMonth，取较早者避免周统计被截断
            $rangeStart = min($thisWeekMonday, $thisMonth);
            /** @var object $userRow */
            $userRow = User::where('created_at', '>=', $rangeStart)
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as monthly', [$thisMonth])
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as weekly', [$thisWeekMonday])
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as daily', [$today])
                ->first();
            $newUsers = [
                'daily' => (int) $userRow->daily,
                'weekly' => (int) $userRow->weekly,
                'monthly' => (int) $userRow->monthly,
            ];

            // 新增有效订单统计：日/周/月（一条 SQL）
            /** @var object $orderRow */
            $orderRow = Order::where('orders.created_at', '>=', $rangeStart)
                ->whereHas('latestCert', function ($q) {
                    $q->whereIn('status', self::ACTIVATING_STATUSES);
                })
                ->selectRaw('SUM(CASE WHEN orders.created_at >= ? THEN 1 ELSE 0 END) as monthly', [$thisMonth])
                ->selectRaw('SUM(CASE WHEN orders.created_at >= ? THEN 1 ELSE 0 END) as weekly', [$thisWeekMonday])
                ->selectRaw('SUM(CASE WHEN orders.created_at >= ? THEN 1 ELSE 0 END) as daily', [$today])
                ->first();
            $newOrders = [
                'daily' => (int) $orderRow->daily,
                'weekly' => (int) $orderRow->weekly,
                'monthly' => (int) $orderRow->monthly,
            ];

            // 每日数据
            $dailyData = [
                'api_calls' => $todayApiStats['calls'],
                'api_errors' => $todayApiStats['errors'],
                'error_rate' => $todayApiStats['error_rate'],
                'version_stats' => $todayApiStats['version_stats'],
            ];

            return [
                'monthly' => $monthlyData,
                'daily' => $dailyData,
                'finance' => $financeData,
                'new_users' => $newUsers,
                'new_orders' => $newOrders,
            ];
        });

        $this->success((array) $data);
    }

    /**
     * 获取实时统计数据
     */
    public function realtime(): void
    {
        $cacheKey = 'dashboard:admin:realtime';
        $cacheMinutes = $this->getCacheMinutes();

        $realtimeStats = Cache::remember($cacheKey, $cacheMinutes * 60, function () {
            $today = now()->startOfDay();
            $now = now();

            // 单条 SQL 合并：到期、签发、处理中统计
            $certStats = DB::selectOne("
                SELECT
                    COUNT(CASE WHEN c.status = 'active' AND c.expires_at BETWEEN ? AND ? THEN 1 END) as exp_7,
                    COUNT(CASE WHEN c.status = 'active' AND c.expires_at BETWEEN ? AND ? THEN 1 END) as exp_30,
                    COUNT(CASE WHEN c.issued_at BETWEEN ? AND ? THEN 1 END) as iss_7,
                    COUNT(CASE WHEN c.issued_at BETWEEN ? AND ? THEN 1 END) as iss_30,
                    COUNT(CASE WHEN c.status IN ('pending', 'processing') THEN 1 END) as processing
                FROM orders o
                JOIN certs c ON o.latest_cert_id = c.id
            ", [
                $now, $now->copy()->addDays(7),
                $now, $now->copy()->addDays(30),
                $now->copy()->subDays(7), $now,
                $now->copy()->subDays(30), $now,
            ]);

            return [
                'online_users' => $this->getOnlineUsersCount(),
                'today' => [
                    'processing_orders' => (int) $certStats->processing,
                    'new_orders' => Order::whereDate('created_at', $today)->count(),
                    'new_users' => User::whereDate('created_at', $today)->count(),
                ],
                'alerts' => [
                    'expiring_7_days' => (int) $certStats->exp_7,
                    'expiring_30_days' => (int) $certStats->exp_30,
                    'issued_7_days' => (int) $certStats->iss_7,
                    'issued_30_days' => (int) $certStats->iss_30,
                ],
            ];
        });

        $this->success((array) $realtimeStats);
    }

    /**
     * 获取趋势数据
     */
    public function trends(Request $request): void
    {
        $days = min(max($request->input('days', 30), 7), 90);

        $cacheKey = "dashboard:admin:trends:$days";
        $cacheMinutes = $this->getCacheMinutes();

        $trends = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($days) {
            $startDate = now()->subDays($days - 1)->startOfDay();

            $dateExpr = 'DATE(created_at)';

            // 用户趋势（GROUP BY 聚合）
            $userCounts = User::where('created_at', '>=', $startDate)
                ->selectRaw("$dateExpr as date, COUNT(*) as cnt")
                ->groupByRaw($dateExpr)
                ->pluck('cnt', 'date');

            // 订单趋势（GROUP BY 聚合）
            $orderCounts = Order::where('created_at', '>=', $startDate)
                ->selectRaw("$dateExpr as date, COUNT(*) as cnt")
                ->groupByRaw($dateExpr)
                ->pluck('cnt', 'date');

            // 充值和消费趋势（一条 SQL 查出所有天数）
            // 用 Query Builder 而非裸 DB::select：Grammar 层处理 PG 下的 `::date` 语法，避开 PDO
            // 对 `::` 的命名参数前缀解析风险（部分 PDO 版本会误判为参数）。
            $financeRows = DB::table('transactions')
                ->where('created_at', '>=', $startDate)
                ->selectRaw(
                    "$dateExpr as date, ".
                    "COALESCE(SUM(CASE WHEN type IN ('addfunds', 'refunds') THEN amount ELSE 0 END), 0) AS recharge, ".
                    "COALESCE(ABS(SUM(CASE WHEN type IN ('order', 'cancel', 'deduct', 'reverse') THEN amount ELSE 0 END)), 0) AS consumption"
                )
                ->groupByRaw($dateExpr)
                ->get();

            $financeMap = [];
            foreach ($financeRows as $row) {
                $financeMap[$row->date] = [
                    'recharge' => round((float) $row->recharge, 2),
                    'consumption' => round((float) $row->consumption, 2),
                ];
            }

            // 组装结果
            $trends = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $dateStr = now()->subDays($i)->format('Y-m-d');
                $trends[] = [
                    'date' => $dateStr,
                    'users' => (int) ($userCounts[$dateStr] ?? 0),
                    'orders' => (int) ($orderCounts[$dateStr] ?? 0),
                    'recharge' => $financeMap[$dateStr]['recharge'] ?? 0.00,
                    'consumption' => $financeMap[$dateStr]['consumption'] ?? 0.00,
                ];
            }

            return $trends;
        });

        $this->success((array) $trends);
    }

    /**
     * 获取产品销售排行
     */
    public function topProducts(Request $request): void
    {
        $days = min(max($request->input('days', 30), 7), 90);
        $limit = min(max($request->input('limit', 10), 5), 50);

        $cacheKey = "dashboard:admin:top_products:$days:$limit";
        $cacheMinutes = $this->getCacheMinutes();

        $topProducts = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($days, $limit) {
            $startDate = now()->subDays($days);

            return Order::select('product_id')
                ->selectRaw('COUNT(*) as order_count')
                ->selectRaw('SUM(amount) as sales_amount')
                ->where('created_at', '>=', $startDate)
                ->groupBy('product_id')
                ->orderByDesc('sales_amount')
                ->limit($limit)
                ->with('product')
                ->get()
                ->map(function (Order $item) {
                    return [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product->name ?? '未知产品',
                        'order_count' => $item->getAttribute('order_count'),
                        'sales_amount' => (float) $item->getAttribute('sales_amount'),
                    ];
                })->toArray();
        });

        $this->success((array) $topProducts);
    }

    /**
     * 获取CA品牌统计
     */
    public function brandStats(Request $request): void
    {
        $days = min(max($request->input('days', 30), 7), 90);

        $cacheKey = "dashboard:admin:brand_stats:$days";
        $cacheMinutes = $this->getCacheMinutes();

        $brandStats = Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($days) {
            $startDate = now()->subDays($days);

            // 基于产品的brand字段统计（LOWER 兼容历史数据大小写不一致）
            return Order::selectRaw('LOWER(products.brand) as brand')
                ->selectRaw('COUNT(orders.id) as total_orders')
                ->selectRaw('SUM(orders.amount) as revenue')
                ->join('products', 'orders.product_id', '=', 'products.id')
                ->where('orders.created_at', '>=', $startDate)
                ->whereNotNull('products.brand')
                ->groupByRaw('LOWER(products.brand)')
                ->orderByDesc('revenue')
                ->get()
                ->map(function (Order $item) {
                    return [
                        'brand' => $item->getAttribute('brand'),
                        'orders' => $item->getAttribute('total_orders'),
                        'revenue' => (float) $item->getAttribute('revenue'),
                    ];
                })->toArray();
        });

        $this->success((array) $brandStats);
    }

    /**
     * 获取用户等级分布
     */
    public function userLevelDistribution(): void
    {
        $cacheKey = 'dashboard:admin:user_level_distribution';
        $cacheMinutes = $this->getCacheMinutes();

        $distribution = Cache::remember($cacheKey, $cacheMinutes * 60, function () {
            return User::select('level_code')
                ->selectRaw('COUNT(*) as user_count')
                ->groupBy('level_code')
                ->get()
                ->map(function (User $item) {
                    return [
                        'level_code' => $item->level_code,
                        'level_name' => $this->getLevelName($item->level_code),
                        'user_count' => $item->getAttribute('user_count'),
                    ];
                })->toArray();
        });

        $this->success((array) $distribution);
    }

    /**
     * 获取财务概览（总余额 / 总欠费）
     */
    public function financeOverview(): void
    {
        $cacheKey = 'dashboard:admin:finance_overview';
        $cacheMinutes = $this->getCacheMinutes();

        $data = Cache::remember($cacheKey, $cacheMinutes * 60, function () {
            $row = DB::selectOne('
                SELECT
                    COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as total_balance,
                    COUNT(CASE WHEN balance > 0 THEN 1 END) as positive_count,
                    COALESCE(SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END), 0) as total_debt,
                    COUNT(CASE WHEN balance < 0 THEN 1 END) as negative_count
                FROM users
            ');

            return [
                'total_balance' => round((float) $row->total_balance, 2),
                'positive_count' => (int) $row->positive_count,
                'total_debt' => round((float) $row->total_debt, 2),
                'negative_count' => (int) $row->negative_count,
            ];
        });

        $this->success((array) $data);
    }

    /**
     * 活动中的证书状态集（与订单搜索 activating 一致）
     */
    private const array ACTIVATING_STATUSES = ['pending', 'processing', 'active', 'approving'];

    /**
     * 获取有效订单数量
     */
    private function getActiveOrdersCount(): int
    {
        return Order::whereHas('latestCert', function ($q) {
            $q->whereIn('status', self::ACTIVATING_STATUSES);
        })->count();
    }

    /**
     * 获取财务数据：日/周/月的充值和消费
     */
    private function getFinanceData($today, $thisWeekMonday, $thisMonth): array
    {
        $prevDayStart = $today->copy()->subDay();
        $prevWeekStart = $thisWeekMonday->copy()->subWeek();
        $prevMonthStart = $thisMonth->copy()->subMonth();

        // 单次扫表，用 CASE WHEN 按日期阈值分桶
        $row = DB::selectOne("
            SELECT
                SUM(CASE WHEN created_at >= ? AND type IN ('addfunds','refunds') THEN amount ELSE 0 END) AS d_r,
                ABS(SUM(CASE WHEN created_at >= ? AND type IN ('order','cancel','deduct','reverse') THEN amount ELSE 0 END)) AS d_c,
                SUM(CASE WHEN created_at >= ? AND type IN ('addfunds','refunds') THEN amount ELSE 0 END) AS w_r,
                ABS(SUM(CASE WHEN created_at >= ? AND type IN ('order','cancel','deduct','reverse') THEN amount ELSE 0 END)) AS w_c,
                SUM(CASE WHEN created_at >= ? AND type IN ('addfunds','refunds') THEN amount ELSE 0 END) AS m_r,
                ABS(SUM(CASE WHEN created_at >= ? AND type IN ('order','cancel','deduct','reverse') THEN amount ELSE 0 END)) AS m_c,
                SUM(CASE WHEN created_at >= ? AND created_at < ? AND type IN ('addfunds','refunds') THEN amount ELSE 0 END) AS pd_r,
                ABS(SUM(CASE WHEN created_at >= ? AND created_at < ? AND type IN ('order','cancel','deduct','reverse') THEN amount ELSE 0 END)) AS pd_c,
                SUM(CASE WHEN created_at >= ? AND created_at < ? AND type IN ('addfunds','refunds') THEN amount ELSE 0 END) AS pw_r,
                ABS(SUM(CASE WHEN created_at >= ? AND created_at < ? AND type IN ('order','cancel','deduct','reverse') THEN amount ELSE 0 END)) AS pw_c,
                SUM(CASE WHEN created_at >= ? AND created_at < ? AND type IN ('addfunds','refunds') THEN amount ELSE 0 END) AS pm_r,
                ABS(SUM(CASE WHEN created_at >= ? AND created_at < ? AND type IN ('order','cancel','deduct','reverse') THEN amount ELSE 0 END)) AS pm_c
            FROM transactions
            WHERE created_at >= ?
        ", [
            $today, $today,
            $thisWeekMonday, $thisWeekMonday,
            $thisMonth, $thisMonth,
            $prevDayStart, $today, $prevDayStart, $today,
            $prevWeekStart, $thisWeekMonday, $prevWeekStart, $thisWeekMonday,
            $prevMonthStart, $thisMonth, $prevMonthStart, $thisMonth,
            $prevMonthStart,
        ]);

        $r = fn ($v) => round((float) ($v ?? 0), 2);

        return [
            'daily' => [
                'recharge' => $r($row->d_r), 'consumption' => $r($row->d_c),
                'prev_recharge' => $r($row->pd_r), 'prev_consumption' => $r($row->pd_c),
            ],
            'weekly' => [
                'recharge' => $r($row->w_r), 'consumption' => $r($row->w_c),
                'prev_recharge' => $r($row->pw_r), 'prev_consumption' => $r($row->pw_c),
            ],
            'monthly' => [
                'recharge' => $r($row->m_r), 'consumption' => $r($row->m_c),
                'prev_recharge' => $r($row->pm_r), 'prev_consumption' => $r($row->pm_c),
            ],
        ];
    }

    /**
     * 获取等级名称
     */
    private function getLevelName(string $levelCode): string
    {
        $levelNames = [
            'standard' => '标准会员',
            'gold' => '金牌会员',
            'platinum' => '铂金会员',
            'crown' => '皇冠会员',
            'partner' => '合作伙伴',
        ];

        return $levelNames[$levelCode] ?? '定制级别';
    }

    /**
     * 获取在线用户数量（近15分钟活跃）
     */
    private function getOnlineUsersCount(): int
    {
        $fifteenMinutesAgo = now()->subMinutes(15);

        return User::where('last_login_at', '>=', $fifteenMinutesAgo)->count();
    }

    /**
     * 获取今日API统计数据
     */
    private function getTodayApiStats(): array
    {
        $today = now()->startOfDay();

        // 单条 SQL：按版本分组统计，汇总即得 total/error
        $versionRows = ApiLog::where('created_at', '>=', $today)
            ->selectRaw('version, COUNT(*) as total_calls')
            ->selectRaw('SUM(CASE WHEN status_code = 200 THEN 1 ELSE 0 END) as success_calls')
            ->selectRaw('SUM(CASE WHEN status_code != 200 THEN 1 ELSE 0 END) as error_calls')
            ->groupBy('version')
            ->orderBy('total_calls', 'desc')
            ->get();

        $totalCalls = $versionRows->sum('total_calls');
        $errorCalls = $versionRows->sum('error_calls');
        $errorRate = $totalCalls > 0 ? round(($errorCalls / $totalCalls) * 100, 2) : 0;

        $versionStats = $versionRows->map(function (ApiLog $item) {
            $totalCalls = $item->getAttribute('total_calls');
            $successCalls = $item->getAttribute('success_calls');
            $successRate = $totalCalls > 0
                ? round(($successCalls / $totalCalls) * 100, 2)
                : 0;

            return [
                'version' => $item->version ?: '未知版本',
                'total_calls' => $totalCalls,
                'success_calls' => $successCalls,
                'error_calls' => $item->getAttribute('error_calls'),
                'success_rate' => $successRate,
            ];
        })->toArray();

        return [
            'calls' => $totalCalls,
            'errors' => $errorCalls,
            'error_rate' => $errorRate,
            'version_stats' => $versionStats,
        ];
    }

    /**
     * 清除Dashboard相关的所有缓存
     */
    public function clearCache(): void
    {
        try {
            // 定义需要清除的缓存键
            $cacheKeys = [
                'dashboard:admin:overview',
                'dashboard:admin:system_overview',
                'dashboard:admin:realtime',
                'dashboard:admin:user_level_distribution',
                'dashboard:admin:finance_overview',
            ];

            // 清除基础缓存
            foreach ($cacheKeys as $key) {
                Cache::forget($key);
            }

            // 清除趋势数据缓存（7-90天）
            for ($days = 7; $days <= 90; $days++) {
                Cache::forget("dashboard:admin:trends:$days");
            }

            // 清除产品排行缓存（7-90天，1-50限制）
            for ($days = 7; $days <= 90; $days++) {
                for ($limit = 1; $limit <= 50; $limit++) {
                    Cache::forget("dashboard:admin:top_products:$days:$limit");
                }
            }

            // 清除品牌统计缓存（7-90天）
            for ($days = 7; $days <= 90; $days++) {
                Cache::forget("dashboard:admin:brand_stats:$days");
            }
        } catch (Exception $e) {
            $this->error('缓存清除失败: '.$e->getMessage());
        }

        $this->success();
    }
}
