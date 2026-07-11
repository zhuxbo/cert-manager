<?php

namespace App\Console\Commands;

use App\Models\Acme;
use App\Models\Task;
use App\Services\Acme\Action;
use App\Services\Notification\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ACME 订单对账（镜像 Order reconcile）——重发卡在 pending 且无 api_id 的 ACME 订单 commit（缺陷4）。
 *
 * 卡单成因：newAndCommit/pay+commit 在扣费落 pending 后、上游提交完成前中断（响应丢失、进程死）。
 * 自愈：重发同 refer_id → 上游 Case A（已成功建单、行有 EAB）幂等返回 order+EAB → commitOrder 回填
 * api_id/EAB/active。Case B（上游占位遗留、EAB 空）恒返通用可重试 msg，与瞬时并发不可区分 → 不做 msg
 * 启发式，只靠 max_attempts 有界退避 → 超限 SystemAlert 转人工（对齐排除项 1 过渡态）。
 * 不发 user 通知（ACME 无 channel=auto 续费语义，到期提醒属 P1-3）。
 */
class ReconcileAcmeCommand extends Command
{
    protected $signature = 'schedule:reconcile-acme';

    protected $description = 'Requeue paid pending ACME orders without upstream api_id';

    // 到顶 count 标量子查询（correlated，锚 acmes.created_at —— ACME 无重签周期，建单时间即周期锚）。
    // (a) 主扫描用 `< ?` 排除到顶、(b) 转人工扫描用 `>= ?` 选中到顶：正反同源，防内部漂移。
    private const MAXED_COUNT_SUBQUERY =
        '(SELECT COUNT(*) FROM tasks t WHERE t.order_id = acmes.id '
        .'AND t.action = ? AND t.status = ? AND t.last_execute_at >= acmes.created_at)';

    public function handle(): int
    {
        $cutoffMinutes = max(1, (int) config('reconcile.acme_cutoff_minutes', 15));
        $limit = max(1, (int) config('reconcile.acme_max_ids', 20));
        $maxAttempts = max(1, (int) config('reconcile.acme_max_attempts', 3));
        $cutoff = now()->subMinutes($cutoffMinutes);

        // (a) 主可动作扫描：pending + null api_id + 过 cutoff，排除到顶（不占 limit，防批量卡单队头阻塞）
        $acmes = Acme::where('status', Acme::STATUS_PENDING)
            ->whereNull('api_id')
            ->where('created_at', '<=', $cutoff)
            ->whereRaw(self::MAXED_COUNT_SUBQUERY.' < ?', ['commit_acme', 'failed', $maxAttempts])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("待对账 ACME 订单数量: {$acmes->count()}");

        $action = app(Action::class);
        $created = 0;
        $skipped = 0;

        foreach ($acmes as $acme) {
            if (! $this->shouldQueueCommit($acme, $maxAttempts)) {
                $skipped++;

                continue;
            }

            try {
                $action->queueCommit((int) $acme->id);
                $created++;
                $this->info("ACME #$acme->id: 已创建 commit_acme 对账任务");
            } catch (Throwable $e) {
                $skipped++;
                $this->error("ACME #$acme->id: 创建 commit_acme 对账任务失败 - {$e->getMessage()}");
            }
        }

        $this->info("ACME 对账完成: created=$created skipped=$skipped");

        // (b) 转人工扫描：到顶 → SystemAlert 逐 acme（与主扫描同 handle 原子，两段式镜像 T5）。前移后主扫描
        // 已排除到顶单、循环内不再触发告警，必须由本扫描承载，否则「前移已落、告警丢失」（07-07 §6 硬依赖）。
        $this->alertMaxedAcmes($maxAttempts, $cutoff);

        return self::SUCCESS;
    }

    private function shouldQueueCommit(Acme $acme, int $maxAttempts): bool
    {
        $acmeId = (int) $acme->id;

        // 二次校验：兜住主扫描 SQL 与本循环之间并发建 executing commit_acme task 的竞态。
        if (Task::where('order_id', $acmeId)->where('action', 'commit_acme')->where('status', 'executing')->exists()) {
            return false;
        }

        // 本周期失败 commit_acme（锚 acme.created_at）——到顶判定已前移主扫描 SQL，此处只算退避倍率。
        $failedTasks = Task::where('order_id', $acmeId)
            ->where('action', 'commit_acme')
            ->where('status', 'failed')
            ->where('last_execute_at', '>=', $acme->created_at)
            ->get();

        $attempts = $failedTasks->count();
        $lastExecutedAt = $failedTasks
            ->pluck('last_execute_at')
            ->filter()
            ->sortDesc()
            ->first();

        if ($lastExecutedAt) {
            $delayMinutes = max(1, (int) config('reconcile.acme_retry_delay_minutes', 10));
            $nextRetryAt = $lastExecutedAt->copy()->addMinutes($delayMinutes * max(1, $attempts));
            if ($nextRetryAt->isFuture()) {
                $this->line("ACME #$acmeId: commit 对账退避中，下次重试 {$nextRetryAt->toDateTimeString()}");

                return false;
            }
        }

        return true;
    }

    /**
     * 转人工扫描：到顶 ACME 订单逐条 SystemAlert（固定指纹，TTL 24h → 每日一封直到人工处理）。
     *
     * 未加 whereNotExists(executing)：被 admin batchStart 重启（executing commit_acme）的到顶单仍会进本扫描、
     * 每日一封 SystemAlert（固定指纹 TTL 24h 有界），仅外观噪音、可接受（对齐 Order 侧 alertMaxedOrders 同类
     * 取舍；ACME 无 user 通知/O4 自动收尾，噪音面更小）。
     */
    private function alertMaxedAcmes(int $maxAttempts, Carbon $cutoff): void
    {
        $acmes = Acme::where('status', Acme::STATUS_PENDING)
            ->whereNull('api_id')
            ->where('created_at', '<=', $cutoff)
            ->whereRaw(self::MAXED_COUNT_SUBQUERY.' >= ?', ['commit_acme', 'failed', $maxAttempts])
            ->get();

        foreach ($acmes as $acme) {
            $acmeId = (int) $acme->id;
            app(SystemAlert::class)->send(
                'acme_reconcile',
                'ACME 订单对账重试耗尽转人工',
                "acme #$acmeId refer_id={$acme->refer_id} 多次提交上游失败，需人工处理",
                ['acme_id' => $acmeId, 'refer_id' => $acme->refer_id], // 键名避 denylist
                "acme_reconcile_maxed_$acmeId",
                24,
                "acme_maxed_$acmeId"
            );
            Log::warning('ACME reconcile reached max attempts', [
                'acme_id' => $acmeId,
                'max_attempts' => $maxAttempts,
            ]);
        }
    }
}
