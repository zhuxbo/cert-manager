<?php

namespace App\Console\Commands;

use App\Jobs\TaskJob;
use App\Models\Task;
use App\Services\Notification\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 重派卡死的僵尸 executing 任务（缺陷1/2 兜底）。
 *
 * 场景：TaskJob dispatch 后 worker 未消费（afterCommit 后 INSERT jobs 失败、worker 重启丢 job、
 * batchStart 恢复的 cancel 任务 delay 缺失等），task 永久 executing 且从未执行（last_execute_at 空
 * 或滞后）。此类僵尸会：① 压制 reconcile（whereNotExists executing 整单排除，卡单永不重发）；
 * ② 静默失效（取消类任务永不退款）。本命令是二者的权威兜底。
 *
 * 幂等：TaskJob::handle 守卫 status=executing AND started_at<=now AND lockForUpdate —— 重派同一 task id，
 * 已接管则 first() 落空 no-op（天然幂等）。sweeper 只锁 task 单行（不碰 order/acme），无跨行锁序问题；
 * 重派后的 TaskJob 自行 task→order/acme 锁序。零迁移：result JSON 承载重派计数（swept_count/last_swept_at）。
 */
class SweepStaleTasksCommand extends Command
{
    protected $signature = 'schedule:sweep-stale-tasks';

    protected $description = 'Redispatch stale executing tasks stuck without being consumed';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) config('reconcile.sweeper.stale_minutes', 30));
        $maxRedispatch = max(1, (int) config('reconcile.sweeper.max_redispatch', 3));
        $minInterval = max(1, (int) config('reconcile.sweeper.min_interval_minutes', 30));
        $batch = max(1, (int) config('reconcile.sweeper.batch', 100));
        $threshold = now()->subMinutes($staleMinutes);

        // 扫描全 action 的僵尸 executing：started_at 已过 stale 阈值（未来延时任务 started_at 在未来，
        // 对 threshold 恒 false → 天然排除，K1）；且从未执行或执行时刻也已滞后。
        $tasks = Task::where('status', 'executing')
            ->where('started_at', '<', $threshold)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_execute_at')
                    ->orWhere('last_execute_at', '<', $threshold);
            })
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $redispatched = 0;
        $exhausted = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            $outcome = $this->sweepOne((int) $task->id, $threshold, $maxRedispatch, $minInterval);
            match ($outcome) {
                'redispatched' => $redispatched++,
                'exhausted' => $exhausted++,
                default => $skipped++,
            };
        }

        $this->info("僵尸任务清理完成: redispatched=$redispatched exhausted=$exhausted skipped=$skipped");

        return self::SUCCESS;
    }

    /**
     * 收敛单个僵尸 task —— 每 task 独立事务（afterCommit 语义 + 单行锁）。
     *
     * @return 'redispatched'|'exhausted'|'skipped'
     */
    private function sweepOne(int $id, Carbon $threshold, int $maxRedispatch, int $minInterval): string
    {
        return DB::transaction(function () use ($id, $threshold, $maxRedispatch, $minInterval) {
            // CAS 复检：TaskJob 若已接管（改 status/started_at）则落空跳过（K2）。锁 task 单行。
            $task = Task::where('id', $id)
                ->where('status', 'executing')
                ->where('started_at', '<', $threshold)
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return 'skipped';
            }

            $result = is_array($task->result) ? $task->result : [];
            $swept = (int) ($result['swept_count'] ?? 0);
            $lastSwept = $result['last_swept_at'] ?? null;

            // 观察期：上轮重派未过 min_interval 勿每 5min 猛派（给重派的 TaskJob 执行窗口，缓解 release 间隙虚增计数）
            if ($lastSwept && Carbon::parse($lastSwept)->gt(now()->subMinutes($minInterval))) {
                return 'skipped';
            }

            if ($swept >= $maxRedispatch) {
                // 上限：停止重派 → 置 stopped（非 executing 解压 reconcile、非 failed 不污染 C4 max_attempts 计数）+ 转人工。
                // swept_count 清零（保留 swept_exhausted_at 痕迹）：admin batchStart 拉起后获得全新重派预算，
                // 人工介入不被历史计数无效化；sweeper 不扫 stopped，无自拉起循环。
                $task->update([
                    'status' => 'stopped',
                    'result' => array_merge($result, [
                        'swept_count' => 0,
                        'swept_exhausted_at' => now()->toDateTimeString(),
                    ]),
                ]);

                // 消息用重置前的 $swept（=maxRedispatch）；details 键名避 denylist（无 cert/key/token 等子串）
                app(SystemAlert::class)->send(
                    'task_stale',
                    '僵尸任务重派耗尽转人工',
                    "task #{$id} action={$task->action} order={$task->order_id} 重派 {$swept} 次仍未消费，已置 stopped，可在任务列表「启动」恢复",
                    ['task_id' => $id, 'task_action' => $task->action, 'order_id' => (int) $task->order_id],
                    "task_swept_exhausted_$id",
                    24,
                    "swept_exhausted_$id"
                );
                Log::warning('stale task redispatch exhausted, marked stopped', [
                    'task_id' => $id,
                    'action' => $task->action,
                    'order_id' => $task->order_id,
                    'swept_count' => $swept,
                ]);

                return 'exhausted';
            }

            $result['swept_count'] = $swept + 1;
            $result['last_swept_at'] = now()->toDateTimeString();
            $task->update(['result' => $result]);

            // 反模式11 硬断言：afterCommit 防事务提交前被消费 + onQueue(tasks) 落生产 worker 监听队列
            TaskJob::dispatch(['id' => $id])
                ->afterCommit()
                ->onQueue(config('queue.names.tasks'));

            return 'redispatched';
        });
    }
}
