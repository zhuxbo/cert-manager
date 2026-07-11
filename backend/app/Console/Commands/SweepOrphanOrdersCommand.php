<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\Action;
use App\Services\Order\PendingReconcileQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * O4：清理 channel=auto 卡死的孤儿续费/重签单（P0-1 路径 3/4）。
 *
 * 两分支各带独立金丝雀开关（分级：unpaid 无资金面默认开、pending 退款默认关待武装）：
 *  - unpaid（stale 超时）→ Action::delete：恢复旧证书 active、删新单，无退款无流水（安全）。
 *  - pending（reconcile 已到顶转人工、非产品缺失）→ Action::cancelPending：退款 + 恢复旧证书（四道网齐）。
 *
 * 到顶/产品缺失判据一律消费 PendingReconcileQuery 共享物（与 reconcile/T5 同源、禁止手抄），
 * maxAttempts 传 config('reconcile.max_attempts') 同源值。O4 只清理已成孤儿，不改建单并发面。
 * 每单独立事务 + 锁内二次校验（delete/cancelPending 原语自带）+ 一单失败不断整批。
 */
class SweepOrphanOrdersCommand extends Command
{
    protected $signature = 'schedule:sweep-orphan-orders';

    protected $description = '清理 channel=auto 卡死的 unpaid（删除恢复）/ pending 到顶（退款）孤儿单';

    public function handle(): int
    {
        $batch = max(1, (int) config('reconcile.orphan.batch', 50));
        $maxAttempts = max(1, (int) config('reconcile.max_attempts', 3));
        $action = app(Action::class);

        $this->sweepUnpaid($action, $batch);
        $this->sweepPending($action, $batch, $maxAttempts);

        return self::SUCCESS;
    }

    /**
     * 分支1：unpaid 超时孤儿 → delete（恢复旧证书、无退款、无流水）。独立开关，默认开。
     */
    private function sweepUnpaid(Action $action, int $batch): void
    {
        if (! config('reconcile.orphan.unpaid_enabled', true)) {
            return;
        }

        $unpaidStale = max(1, (int) config('reconcile.orphan.unpaid_stale_minutes', 60));
        $cutoff = now()->subMinutes($unpaidStale);

        $orders = Order::with('latestCert')
            ->whereHas('latestCert', function ($query) use ($cutoff) {
                $query->where('status', 'unpaid')
                    ->where('channel', 'auto')
                    ->where('created_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($batch)
            ->get();

        foreach ($orders as $order) {
            try {
                // delete 锁内只放行 unpaid（并发变更即报错跳过，无害）；renew 删新单 / reissue 回切 latest_cert_id
                $action->delete((int) $order->id);
                $this->info("孤儿 unpaid #{$order->id} 已删除并恢复旧证书");
            } catch (Throwable $e) {
                $this->warn("unpaid #{$order->id} 删除跳过: {$e->getMessage()}");
            }
        }
    }

    /**
     * 分支2：pending 到顶（reconcile 已转人工、非产品缺失）→ cancelPending（退款 + 恢复旧证书）。
     * 独立开关，默认关（arm-switch，首轮生产观察后手动开启）。
     */
    private function sweepPending(Action $action, int $batch, int $maxAttempts): void
    {
        if (! config('reconcile.orphan.pending_enabled', false)) {
            return;
        }

        // 三条件全部消费 PendingReconcileQuery 共享物 / 镜像 reconcile：
        //  ① 到顶 AND ② 非产品缺失 = maxedAndNotProductMissing（= T5(b) 转人工集 ∩ 非产品缺失，正反同源无漂移）
        //  ③ 无 executing commit task（镜像 reconcile C3，等最后一次 commit 落定）
        $orders = PendingReconcileQuery::maxedAndNotProductMissing(
            Order::with('latestCert')
                ->whereHas('latestCert', function ($query) {
                    $query->where('status', 'pending')
                        ->whereNull('api_id')
                        ->where('channel', 'auto');
                })
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))->from('tasks')
                        ->whereColumn('tasks.order_id', 'orders.id')
                        ->where('tasks.action', 'commit')
                        ->where('tasks.status', 'executing');
                }),
            $maxAttempts
        )
            ->orderBy('orders.id')
            ->limit($batch)
            ->get();

        foreach ($orders as $order) {
            try {
                // cancelPending 锁内二次校验 status=pending（并发 late-commit 推 processing 即早退，不误退款）
                $action->cancelPending((int) $order->id);
                $this->info("孤儿 pending #{$order->id} 已取消退款并恢复旧证书");
            } catch (Throwable $e) {
                $this->warn("pending #{$order->id} 取消跳过: {$e->getMessage()}");
            }
        }
    }
}
