<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Task;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Requeue paid Order certificates that are stuck before upstream commit.
 */
class ReconcilePendingCommand extends Command
{
    protected $signature = 'schedule:reconcile-pending';

    protected $description = 'Requeue paid pending orders without upstream api_id';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) config('reconcile.pending_stale_minutes', 10));
        $limit = max(1, (int) config('reconcile.max_reconcile_ids', 20));
        $cutoff = now()->subMinutes($staleMinutes);

        $orders = Order::with('latestCert')
            ->whereHas('latestCert', function ($query) use ($cutoff) {
                $query->where('status', 'pending')
                    ->whereNull('api_id')
                    ->where('created_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("待对账 pending 订单数量: {$orders->count()}");

        $action = app(Action::class);
        $created = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            if (! $this->shouldQueueCommit((int) $order->id)) {
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

    private function shouldQueueCommit(int $orderId): bool
    {
        if ($this->hasExecutingCommitTask($orderId)) {
            return false;
        }

        $failedTasks = Task::where('order_id', $orderId)
            ->where('action', 'commit')
            ->where('status', 'failed')
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
            $this->alertMaxAttempts($orderId, $failedTasks, $attempts, $maxAttempts);

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

    private function alertMaxAttempts(int $orderId, $failedTasks, int $attempts, int $maxAttempts): void
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
        if (isset($result['reconcile_alerted_at'])) {
            return;
        }

        try {
            $adminEmail = get_system_setting('site', 'adminEmail');
            $admin = $adminEmail ? Admin::where('email', $adminEmail)->first() : null;
            $admin ??= Admin::first();

            if (! $admin?->email) {
                $this->warn("订单 #$orderId: 未找到管理员邮箱，跳过人工告警（已落日志）");

                return;
            }

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
        } catch (Throwable $e) {
            $this->warn("订单 #$orderId: 人工告警发送失败 - {$e->getMessage()}");
            Log::warning('pending order reconcile alert failed', [
                'order_id' => $orderId,
                'task_id' => $task->id,
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
