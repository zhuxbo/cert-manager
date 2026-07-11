<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Task;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use Illuminate\Console\Command;
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
     * C2：reconcile 到顶时给自动续签用户的 auto_renew_failed 通知理由（用户可行动文案）。
     */
    private const RECONCILE_USER_REASON = '证书提交上游多次失败，请稍后重试或联系客服';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) config('reconcile.pending_stale_minutes', 10));
        $limit = max(1, (int) config('reconcile.max_reconcile_ids', 20));
        $cutoff = now()->subMinutes($staleMinutes);

        $orders = Order::with(['latestCert', 'user']) // C2：eager load user 供到顶时发用户通知
            ->whereHas('latestCert', function ($query) use ($cutoff) {
                $query->where('status', 'pending')
                    ->whereNull('api_id')
                    ->where('created_at', '<=', $cutoff);
            })
            // C3：把「有 executing commit task」的订单下沉进 SQL 过滤，不占 limit 名额（否则 00:00 批量
            // 续签的延时 commit executing 单会挤满窗口、饿死真正可动作的卡单）。与 hasExecutingCommitTask
            // 逐字等价；shouldQueueCommit 内二次校验保留，兜住 SQL 查询与循环之间并发建 task 的竞态。
            // 注意：只下沉 executing 过滤，到顶订单（有 failed 无 executing）不被排除，仍进循环仍告警。
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))->from('tasks')
                    ->whereColumn('tasks.order_id', 'orders.id')
                    ->where('tasks.action', 'commit')
                    ->where('tasks.status', 'executing');
            })
            ->orderBy('id')
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

        return self::SUCCESS;
    }

    private function shouldQueueCommit(Order $order): bool
    {
        $orderId = (int) $order->id;

        if ($this->hasExecutingCommitTask($orderId)) {
            return false;
        }

        // C4：只统计「当前 pending 证书」这一续期/重签周期内的失败 commit task。全历史 count 会把该 order
        // 早前已解决的卡单周期（如上一张证书）也计入，使二次卡单（新 pending cert，如 reissue）被旧账
        // 直接判到顶、跳过重试。本周期的 commit task 必在该证书创建之后执行，故用 last_execute_at >= 锚点
        // （优于固定时长窗口：固定窗会让 genuinely 卡死超窗的旧账过期→无限重发+重复扣费）。
        $cycleStart = $order->latestCert?->created_at;
        $failedTasks = Task::where('order_id', $orderId)
            ->where('action', 'commit')
            ->where('status', 'failed')
            ->when($cycleStart, fn ($query) => $query->where('last_execute_at', '>=', $cycleStart))
            ->get();

        // 每个对账周期最多建一个 commit task，故「失败 commit task 的行数」= 失败的对账周期数。
        // 不用 $failedTasks->sum('attempts')：那会把 worker 对单个 task 的重试次数并进来，
        // 单个 task 被重试到 attempts>=max 就会在约一个对账周期后即误判到顶、过早转人工告警
        // （worker 单任务重试 ≠ 对账周期数）。退避倍率与告警阈值都以对账周期数为准。
        $attempts = $failedTasks->count();
        $maxAttempts = max(1, (int) config('reconcile.max_attempts', 3));

        if ($attempts >= $maxAttempts) {
            $this->warn("订单 #$orderId: commit 对账重试已达上限 {$attempts}/{$maxAttempts}，转人工处理");
            Log::warning('pending order reconcile reached max attempts', [
                'order_id' => $orderId,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
            ]);
            $this->alertMaxAttempts($order, $failedTasks, $attempts, $maxAttempts);

            return false;
        }

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

    private function alertMaxAttempts(Order $order, $failedTasks, int $attempts, int $maxAttempts): void
    {
        $task = $failedTasks
            ->sortByDesc(fn (Task $task) => sprintf(
                '%020d-%020d',
                $task->last_execute_at?->getTimestamp() ?? 0,
                $task->id
            ))
            ->first();

        if (! $task instanceof Task) {
            return;
        }

        $result = is_array($task->result) ? $task->result : [];
        $cert = $order->latestCert;
        // C2：用户通知仅 channel=auto 且有邮箱适用——模板文案「自动X失败」只对自动续签单语义正确；
        // 手动 web/api/deploy 单用户自己发起、订单列表可见卡单，发「自动X失败」会误导，不发。
        $userApplicable = $cert && $cert->channel === 'auto' && $order->user?->email;
        $adminDone = isset($result['reconcile_alerted_at']);
        $userDone = ! $userApplicable || isset($result['reconcile_user_alerted_at']);

        // 双键分判早返：admin 已发【且】(user 已发或不适用) 才整体返回。存量单（admin 键已在、user 键缺失）
        // 不再被顶部早返挡住——下一轮即补发用户通知，闭合 C2 上线前已 admin 告警的在途 auto 卡单缺口。
        if ($adminDone && $userDone) {
            return;
        }

        // admin 块：与既有实现一致，独立 try/catch，dispatch 成功后落 admin 三字段
        if (! $adminDone) {
            try {
                $adminEmail = get_system_setting('site', 'adminEmail');
                $admin = $adminEmail ? Admin::where('email', $adminEmail)->first() : null;
                $admin ??= Admin::first();

                if (! $admin?->email) {
                    $this->warn("订单 #{$order->id}: 未找到管理员邮箱，跳过人工告警（已落日志）");
                } else {
                    app(NotificationCenter::class)->dispatch(new NotificationIntent(
                        'task_failed',
                        'admin',
                        $admin->id,
                        [
                            'task_id' => $task->id,
                            'admin_email' => $adminEmail ?: $admin->email,
                            'error_message' => "pending order commit reconcile reached max attempts {$attempts}/{$maxAttempts}",
                        ]
                    ));

                    $result['reconcile_alerted_at'] = now()->toDateTimeString();
                    $result['reconcile_attempts'] = $attempts;
                    $result['reconcile_max_attempts'] = $maxAttempts;
                    $task->forceFill(['result' => $result])->save();
                }
            } catch (Throwable $e) {
                $this->warn("订单 #{$order->id}: 人工告警发送失败 - {$e->getMessage()}");
                Log::warning('pending order reconcile alert failed', [
                    'order_id' => $order->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // user 块：独立键 + 独立 try/catch（与 admin 故障互不阻塞、失败不落键下轮重试）
        if ($userApplicable && ! isset($result['reconcile_user_alerted_at'])) {
            try {
                app(NotificationCenter::class)->dispatch(new NotificationIntent(
                    'auto_renew_failed',
                    'user',
                    $order->user->id,
                    [
                        'common_name' => $cert->common_name,
                        'action' => $cert->action, // renew / reissue → 模板映射续费/重签
                        'reason' => self::RECONCILE_USER_REASON,
                        'email' => $order->user->email,
                    ]
                ));

                $result['reconcile_user_alerted_at'] = now()->toDateTimeString();
                $task->forceFill(['result' => $result])->save();
            } catch (Throwable $e) {
                $this->warn("订单 #{$order->id}: 用户通知发送失败 - {$e->getMessage()}");
                Log::warning('reconcile user notify failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
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
