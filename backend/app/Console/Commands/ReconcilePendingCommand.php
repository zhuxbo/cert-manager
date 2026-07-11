<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Task;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Action;
use App\Services\Order\PendingReconcileQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Requeue paid Order certificates that are stuck before upstream commit.
 */
class ReconcilePendingCommand extends Command
{
    protected $signature = 'schedule:reconcile-pending';

    protected $description = 'Requeue paid pending orders without upstream api_id';

    /**
     * 到顶（多次提交上游失败）时给自动续签用户的 auto_renew_failed 通知理由。
     *
     * 中性措辞：不承诺「请稍后重试」——后续包O O4 会在转人工后自动取消并退款，承诺式措辞会误导用户
     * 以为需自行重试。此处如实告知「正在处理、长时间未完成将自动取消并退款」。
     */
    private const RECONCILE_USER_REASON = '证书提交上游多次未成功，我们正在处理；若长时间未完成将自动取消并退款';

    /**
     * 产品缺失（上游产品下线/删除）专属理由：这是用户可行动项（需重新选购），非承诺式措辞。
     */
    private const RECONCILE_USER_REASON_PRODUCT_MISSING = '所选产品已下线，请重新选购后提交';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) config('reconcile.pending_stale_minutes', 10));
        $limit = max(1, (int) config('reconcile.max_reconcile_ids', 20));
        $maxAttempts = max(1, (int) config('reconcile.max_attempts', 3));
        $cutoff = now()->subMinutes($staleMinutes);

        // (a) 主可动作扫描：现状 whereHas(pending+null api_id+cutoff) + whereNotExists(executing commit)
        // 叠加 PendingReconcileQuery::actionable（JOIN certs 锚点 + 排除到顶/产品缺失），到顶/产品缺失单
        // 不再占 limit 名额（否则批量产品下线/持续失败会挤满窗口、饿死真正可动作的卡单——队头阻塞）。
        //
        // 【受保护不变式：本主扫描不得加 channel 过滤】——O4 sweep-orphan-orders 限定 channel=auto 接手，
        // Deploy/api 渠道的 pending 孤儿全靠本主扫描（无 channel 过滤）接住瞬态自愈；加了 channel 过滤 =
        // Deploy/api 安全网静默消失（O 计划受保护不变式，ReconcilePendingCommandTest 有护栏用例守此）。
        $orders = PendingReconcileQuery::actionable(
            Order::with('latestCert') // 仅 latestCert（C4 退避锚点）；user 通知已迁 (b) 转人工扫描，本 (a) 扫描不消费 user
                ->whereHas('latestCert', function ($query) use ($cutoff) {
                    $query->where('status', 'pending')
                        ->whereNull('api_id')
                        ->where('created_at', '<=', $cutoff);
                })
                // 把「有 executing commit task」的订单下沉进 SQL 过滤，不占 limit 名额。与 hasExecutingCommitTask
                // 逐字等价；shouldQueueCommit 内二次校验保留，兜住 SQL 查询与循环之间并发建 task 的竞态。
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))->from('tasks')
                        ->whereColumn('tasks.order_id', 'orders.id')
                        ->where('tasks.action', 'commit')
                        ->where('tasks.status', 'executing');
                }),
            $maxAttempts
        )
            ->orderBy('orders.id')
            ->limit($limit)
            ->get();

        $this->info("待对账 pending 订单数量: {$orders->count()}");

        $action = app(Action::class);
        $created = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            if (! $this->shouldQueueCommit($order)) {
                $skipped++;

                continue;
            }

            try {
                $before = $this->hasExecutingCommitTask((int) $order->id);
                $action->createTask((int) $order->id, 'commit');
                $after = $this->hasExecutingCommitTask((int) $order->id);

                if (! $before && $after) {
                    $created++;
                    $this->info("订单 #$order->id: 已创建 commit 对账任务");
                } else {
                    $skipped++;
                    $this->line("订单 #$order->id: 已存在 commit 执行任务，跳过");
                }
            } catch (Throwable $e) {
                $skipped++;
                $this->error("订单 #$order->id: 创建 commit 对账任务失败 - {$e->getMessage()}");
            }
        }

        $this->info("对账完成: created=$created skipped=$skipped");

        // (b) 转人工扫描：到顶/产品缺失统一处理（user 逐单 + admin 每日快照）。与 (a) 排除同一 handle 原子落地
        // —— 前移后 (a) 内不再触发到顶告警，必须由本扫描承载，否则「前移已落、告警丢失」（07-07 §6 硬依赖）。
        $this->alertMaxedOrders($maxAttempts, $cutoff);

        return self::SUCCESS;
    }

    private function shouldQueueCommit(Order $order): bool
    {
        $orderId = (int) $order->id;

        // 二次校验：兜住主扫描 SQL 与本循环之间并发建 executing commit task 的竞态。
        if ($this->hasExecutingCommitTask($orderId)) {
            return false;
        }

        // C4：只统计「当前 pending 证书」这一续期/重签周期内的失败 commit task（last_execute_at >= 锚点），
        // 用于退避倍率计算。到顶判定已前移到主扫描 SQL（PendingReconcileQuery::actionable）——本方法只在
        // 「未到顶」结果集内被调用，故此处不再做 max_attempts 早退/告警（转人工由 handle 末尾独立扫描承载）。
        $cycleStart = $order->latestCert?->created_at;
        $failedTasks = Task::where('order_id', $orderId)
            ->where('action', 'commit')
            ->where('status', 'failed')
            ->when($cycleStart, fn ($query) => $query->where('last_execute_at', '>=', $cycleStart))
            ->get();

        // 每个对账周期最多建一个 commit task，故行数 = 失败的对账周期数（非 sum worker attempts）。
        $attempts = $failedTasks->count();

        $lastExecutedAt = $failedTasks
            ->pluck('last_execute_at')
            ->filter()
            ->sortDesc()
            ->first();

        if ($lastExecutedAt) {
            $delayMinutes = max(1, (int) config('reconcile.retry_delay_minutes', 10));
            $nextRetryAt = $lastExecutedAt->copy()->addMinutes($delayMinutes * max(1, $attempts));
            if ($nextRetryAt->isFuture()) {
                $this->line("订单 #$orderId: commit 对账退避中，下次重试 {$nextRetryAt->toDateTimeString()}");

                return false;
            }
        }

        return true;
    }

    /**
     * 转人工扫描：到顶 OR 产品缺失订单统一处理 —— user 逐单通知（双键去重）+ admin 每日快照告警。
     *
     * 不占 limit（无 limit）：候选 = 卡单终局态总量，正常有界（数十以内）；未加 whereNotExists(executing)：
     * 被 admin batchStart 重启的到顶单可能短暂进本扫描（user 键已落不重发，仅 admin 每日快照多列一个 id
     * 的外观噪音，可接受）。到顶单自动收尾（cancelPending 退款）属包O O4，本扫描正是 O4「已转人工」判据来源。
     */
    private function alertMaxedOrders(int $maxAttempts, Carbon $cutoff): void
    {
        $orders = PendingReconcileQuery::maxedOrProductMissing(
            Order::with(['latestCert', 'user'])
                ->whereHas('latestCert', function ($query) use ($cutoff) {
                    $query->where('status', 'pending')
                        ->whereNull('api_id')
                        ->where('created_at', '<=', $cutoff);
                }),
            $maxAttempts
        )->get();

        $summaryRows = [];

        foreach ($orders as $order) {
            $cycleStart = $order->latestCert?->created_at;
            $failedTasks = Task::where('order_id', $order->id)
                ->where('action', 'commit')
                ->where('status', 'failed')
                ->when($cycleStart, fn ($query) => $query->where('last_execute_at', '>=', $cycleStart))
                ->get();

            // 取本周期最新 failed commit task（last_execute_at 最新、ts 同取 id 大）——键落点稳定。
            $task = $failedTasks
                ->sortByDesc(fn (Task $task) => sprintf(
                    '%020d-%020d',
                    $task->last_execute_at?->getTimestamp() ?? 0,
                    $task->id
                ))
                ->first();

            if (! $task instanceof Task) {
                continue;
            }

            $isProductMissing = PendingReconcileQuery::matchesProductMissing($task->result['msg'] ?? null);
            $this->notifyUserForMaxedOrder($order, $task, $isProductMissing);
            $summaryRows[] = ['order_id' => (int) $order->id, 'product_missing' => $isProductMissing];
        }

        if (empty($summaryRows)) {
            return;
        }

        $maxedTotal = count(array_filter($summaryRows, fn ($row) => ! $row['product_missing']));
        $productMissingTotal = count($summaryRows) - $maxedTotal;
        $sample = implode(',', array_slice(array_map(fn ($row) => $row['order_id'], $summaryRows), 0, 20));

        // 每日快照 admin 告警（吸收 M5）：fingerprint 含当天日期 → 每天首个非空轮发一封含当天全部转人工单，
        // 同日指纹相同不重发，次日指纹变化必发（含存量+新增，防 SMTP 瞬断永久丢失）。新增单最坏延迟 1 天。
        // details 键名避 denylist（total/maxed_total/product_missing_total/order_sample，无 cert/key/token）。
        app(SystemAlert::class)->send(
            'reconcile_maxed',
            '卡单对账转人工每日汇总',
            count($summaryRows).' 单已达重试上限或产品缺失，需人工处理（后续自动取消并退款）',
            [
                'total' => count($summaryRows),
                'maxed_total' => $maxedTotal,
                'product_missing_total' => $productMissingTotal,
                'order_sample' => $sample,
            ],
            'reconcile_order_maxed_summary',
            24,
            'maxed_summary_'.now()->toDateString()
        );
    }

    /**
     * 到顶/产品缺失订单的 user 逐单通知（复刻 C2：channel=auto+email gate、单周期一封、dispatch 成功后落键）。
     */
    private function notifyUserForMaxedOrder(Order $order, Task $task, bool $isProductMissing): void
    {
        $result = is_array($task->result) ? $task->result : [];
        $cert = $order->latestCert;

        // C2：用户通知仅 channel=auto 且有邮箱适用——「自动X失败」模板只对自动续签单语义正确；手动单会误导。
        $userApplicable = $cert && $cert->channel === 'auto' && $order->user?->email;

        if (! $userApplicable || isset($result['reconcile_user_alerted_at'])) {
            // 不适用，或本卡单周期已发过（前移后到顶单不建新 commit task，「本周期最新 failed task」稳定 → 每周期一封）
            return;
        }

        try {
            app(NotificationCenter::class)->dispatch(new NotificationIntent(
                'auto_renew_failed',
                'user',
                $order->user->id,
                [
                    'common_name' => $cert->common_name,
                    'action' => $cert->action, // renew / reissue → 模板映射续费/重签
                    'reason' => $isProductMissing ? self::RECONCILE_USER_REASON_PRODUCT_MISSING : self::RECONCILE_USER_REASON,
                    'email' => $order->user->email,
                ]
            ));

            $result['reconcile_user_alerted_at'] = now()->toDateTimeString();
            $task->forceFill(['result' => $result])->save(); // dispatch 成功后落键（失败不落键、下轮重试）
        } catch (Throwable $e) {
            $this->warn("订单 #{$order->id}: 用户通知发送失败 - {$e->getMessage()}");
            Log::warning('reconcile user notify failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hasExecutingCommitTask(int $orderId): bool
    {
        return Task::where('order_id', $orderId)
            ->where('action', 'commit')
            ->where('status', 'executing')
            ->exists();
    }
}
