<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Notification\SystemAlert;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * E6 卡单聚合告警（P2）。
 *
 * 调度：schedule:stuck-orders，每天 06:30（freeze 期 skip）。
 *
 * 只读不写：仅聚合 processing/approving 长期卡单发一封 admin 告警，绝不改订单/证书状态、
 * 不退款（自动处理归 P0/reconcile）。
 *
 * 阈值按 products.validation_type 分档（OV/EV 组织验证合法耗时数天~两周，dv:7/ov:14/ev:21，
 * config 可调）；null/未知 validation_type 回落最长档（防误报方向）。锚点用 cert.created_at
 * （与 ReconcilePendingCommand 同源；updated_at 被 validate 每分钟刷新不稳）。
 *
 * 首跑存量为预期：查询无 created_at 下界，首次上线会把全部历史久卡单一次性计入——首封为
 * 一封聚合长邮件（count 可能上百、样本限 20），此后周去重每周至多一封直至存量被
 * sync/Purge 收敛或人工处理。
 */
class StuckOrdersCommand extends Command
{
    protected $signature = 'schedule:stuck-orders';

    protected $description = '聚合 processing/approving 长期卡单并告警（按 validation_type 分档，只读）';

    private const DEDUPE_KEY = 'stuck_orders';

    private const STUCK_STATUSES = ['processing', 'approving'];

    private const SAMPLE_LIMIT = 20;

    public function handle(): int
    {
        if (! config('monitoring.stuck_orders.enabled', true)) {
            return self::SUCCESS;
        }

        $days = (array) config('monitoring.stuck_orders.stuck_days', ['dv' => 7, 'ov' => 14, 'ev' => 21]);
        $dvDays = (int) ($days['dv'] ?? 7);
        $ovDays = (int) ($days['ov'] ?? 14);
        $evDays = (int) ($days['ev'] ?? 21);
        // null/未知 validation_type 回落最长档（防误报方向）
        $fallbackDays = max($dvDays, $ovDays, $evDays);

        $dv = $this->stuckOrders(fn ($q) => $q->where('validation_type', 'dv'), $dvDays);
        $ov = $this->stuckOrders(fn ($q) => $q->where('validation_type', 'ov'), $ovDays);
        $ev = $this->stuckOrders(fn ($q) => $q->where('validation_type', 'ev'), $evDays);
        // 第 4 条：null/未知回落档——grouped whereNull/orWhereNotIn（裸 whereNotIn 对 NULL 行恒 false，会漏 null）
        $other = $this->stuckOrders(function ($q) {
            $q->where(function ($q2) {
                $q2->whereNull('validation_type')
                    ->orWhereNotIn('validation_type', ['dv', 'ov', 'ev']);
            });
        }, $fallbackDays);

        $counts = [
            'dv' => $dv->count(),
            'ov' => $ov->count(),
            'ev' => $ev->count(),
            'other' => $other->count(),
        ];
        $total = array_sum($counts);

        // 零卡单：清去重键（存量收敛后下次卡单立即告警）
        if ($total === 0) {
            app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);

            return self::SUCCESS;
        }

        // 样本拍平为标量串（order_id domain 档位），键名避 denylist 子串
        $sample = [];
        foreach (['dv' => $dv, 'ov' => $ov, 'ev' => $ev, 'other' => $other] as $tier => $orders) {
            foreach ($orders as $order) {
                $sample[] = $order->id.' '.($order->latestCert->common_name ?? '').' '.$tier;
                if (count($sample) >= self::SAMPLE_LIMIT) {
                    break 2;
                }
            }
        }

        $details = [
            'total' => $total,
            'dv' => $counts['dv'],
            'ov' => $counts['ov'],
            'ev' => $counts['ev'],
            'other' => $counts['other'],
            'sample' => implode('; ', $sample),
        ];

        app(SystemAlert::class)->send(
            'stuck_orders',
            'processing/approving 卡单超阈',
            "卡单 {$total} 单超阈：dv={$counts['dv']} ov={$counts['ov']} ev={$counts['ev']} other={$counts['other']}",
            $details,
            self::DEDUPE_KEY,
            (int) config('monitoring.stuck_orders.dedupe_ttl_hours', 168),
            'stuck', // 固定指纹：样本/计数逐日波动不 churn，每周至多一封
        );

        return self::SUCCESS;
    }

    /**
     * 查询某一档卡单：product 命中 $productFilter，latestCert 处于 processing/approving
     * 且 cert.created_at 早于 now-$days 天。
     *
     * @return Collection<int, Order>
     */
    private function stuckOrders(Closure $productFilter, int $days): Collection
    {
        return Order::query()
            ->whereHas('product', $productFilter)
            ->whereHas('latestCert', function ($q) use ($days) {
                $q->whereIn('status', self::STUCK_STATUSES)
                    ->where('created_at', '<', now()->subDays($days));
            })
            ->with(['latestCert', 'product'])
            ->get();
    }
}
