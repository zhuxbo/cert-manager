<?php

namespace App\Console\Commands;

use App\Exceptions\ApiResponseException;
use App\Models\AutoDeployReport;
use App\Models\Fund;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Task;
use App\Services\Order\Action;
use App\Services\Order\AutoDeployReportService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class PurgeCommand extends Command
{
    private const SYNC_INTERVAL_HOURS = 24;

    private const TASK_AUDIT_ACTIONS = ['commit', 'commit_acme', 'cancel', 'cancel_acme', 'callback'];

    private const TASK_DIAGNOSTIC_ACTIONS = ['sync', 'sync_acme', 'revalidate', 'delegation'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedule:purge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge expired runtime and operational data';

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): int
    {
        $this->info(get_system_setting('site', 'name', 'SSL证书管理系统'));
        $maintenanceFailed = false;

        // 清理超过24小时的未支付充值
        $result = Fund::where('created_at', '<', now()->subHours(24))->where('status', 0)->delete();
        $this->info("Purged $result fund records");

        // 清理已签发订单的用户上传文档（保留验证报告表单）
        $this->purgeIssuedOrderDocuments();

        // 清理 storage/temp-certs 下超过 1 小时的残留（下载中断/异常/exit 未清理的临时证书目录，含私钥）
        $this->purgeStaleTempCerts();

        // 清理超保留期的终态运行时表行（对账痕迹 tasks / 交付记录 notifications / 自动部署上报 auto_deploy_reports）
        // 各运行时表清理故障隔离：清理是次要职责，抛错不得中止后续退款期取消主流程
        foreach (['tasks' => 'purgeTerminalTasks', 'notifications' => 'purgeTerminalNotifications', 'reports' => 'purgeTerminalOrderReports'] as $owner => $method) {
            try {
                $this->{$method}();
            } catch (Throwable $e) {
                $maintenanceFailed = true;
                $this->warn("Terminal $owner cleanup failed: ".class_basename($e));
            }
        }

        // 预同步：距退款期限2-4天的处理中订单，24小时内无同步则创建sync任务
        // 退款期限<5天的产品跳过由人工控制
        // 同时避免 refund_period UNSIGNED 减法溢出
        $preSyncOrders = Order::with(['latestCert'])
            ->join('products', 'orders.product_id', '=', 'products.id')
            ->whereHas('latestCert', fn ($query) => $query
                ->where('status', 'processing')
                ->whereIn('action', ['new', 'renew', 'reissue']))
            ->where('products.refund_period', '>=', 5)
            ->where('orders.created_at', '<=', DB::raw('DATE_SUB(NOW(), INTERVAL (products.refund_period - 4) DAY)'))
            ->where('orders.created_at', '>', DB::raw('DATE_SUB(NOW(), INTERVAL (products.refund_period - 2) DAY)'))
            ->select('orders.*')
            ->get();

        $preSyncCount = 0;
        foreach ($preSyncOrders as $order) {
            if (! $this->hasRecentSyncAttempt($order->id)) {
                $action = app(Action::class);
                $action->createTask($order->id, 'sync');
                $preSyncCount++;
            }
        }
        $this->info("Pre-sync created for $preSyncCount orders");

        // 取消临近退款期限的处理中订单（还剩2天内的订单）
        // 退款期限<5天的产品跳过，同上
        $orders = Order::with(['latestCert'])
            ->join('products', 'orders.product_id', '=', 'products.id')
            ->whereHas('latestCert', fn ($query) => $query
                ->where('status', 'processing')
                ->whereIn('action', ['new', 'renew', 'reissue']))
            ->where('products.refund_period', '>=', 5)
            ->where('orders.created_at', '>', DB::raw('DATE_SUB(NOW(), INTERVAL products.refund_period DAY)'))
            ->where('orders.created_at', '<=', DB::raw('DATE_SUB(NOW(), INTERVAL (products.refund_period - 2) DAY)'))
            ->select('orders.*')
            ->get();

        if ($orders->isNotEmpty()) {
            $canceledCount = 0;
            $action = app(Action::class);

            foreach ($orders as $order) {
                try {
                    // 取消前执行即时同步（含上游 HTTP，不能进锁）
                    if (! $this->syncImmediately($action, $order)) {
                        $this->info("Order $order->id: sync error, skip cancel");

                        continue;
                    }

                    // 刷新证书状态（锁外预检；commitCancel 锁内会再校验一次）
                    $order->latestCert->refresh();
                    if ($order->latestCert->status !== 'processing') {
                        $this->info("Order $order->id: status changed to $order->latestCert->status after sync, skip cancel");

                        continue;
                    }

                    // 仍是 processing，走 commitCancel 取消：锁序合规（task→order + 锁内二次校验），
                    // 取代原「cert 置 cancelling → deleteTask → createTask」三步裸调（order→task 反序、无锁）。
                    // commitCancel 成功末尾抛 ApiResponseException(code=1)（DB 副作用已提交后才抛），
                    // 必须捕获判 code 计数——绝不裸调：裸调会被外层 catch 把成功当失败打印、canceledCount 恒 0。
                    try {
                        $action->commitCancel($order->id);
                    } catch (ApiResponseException $e) {
                        ($e->getApiResponse()['code'] ?? 0) === 1
                            ? $canceledCount++ // 成功：success() 抛 code=1
                            : $this->info("Order $order->id: cancel skipped - ".($e->getApiResponse()['msg'] ?? ''));
                    }
                } catch (Throwable $e) {
                    $this->error("Failed to process order $order->id: ".$e->getMessage());
                }
            }

            $this->info("Set $canceledCount orders to cancelling status: ".$orders->pluck('id')->implode(','));
        } else {
            $this->info('No orders to cancel near refund deadline');
        }

        return $maintenanceFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 清理 storage/temp-certs 下超过 1 小时的残留目录/文件（含证书私钥、pfx、password.txt）。
     *
     * 泄漏来源：下载建包相位异常（downFlow 未调用）、readfile 中途客户端断连致脚本中止（exit/中止
     * 都跑不到 downFlow 内的清理与 finally）。这是唯一能覆盖 exit/中止残留的兜底。
     * 阈值 1h ≫ 下载时长，进行中下载（mtime≈now）永不命中；仅扫直接子项、跳符号链接
     * （路径名为 random、无用户输入，无遍历面）。
     */
    private function purgeStaleTempCerts(): void
    {
        $base = storage_path('temp-certs');
        if (! is_dir($base)) {
            return;
        }

        $cutoff = time() - 3600;
        $cleared = 0;

        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$base/$entry";
            if (is_link($path)) {
                continue; // 不跟随符号链接
            }
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
            $cleared++;
        }

        $this->info("Purged $cleared stale temp-cert entries");
    }

    private function purgeTerminalTasks(): void
    {
        $fullDays = (int) config('purge.retention.tasks_full_days', 7);
        $auditDays = (int) config('purge.retention.tasks_audit_days', 180);
        if ($fullDays <= 0 || $auditDays <= $fullDays || (int) config('purge.chunk', 1000) <= 0) {
            throw new \InvalidArgumentException('Invalid task retention configuration');
        }

        $fullCutoff = now()->subDays($fullDays);
        $auditCutoff = now()->subDays($auditDays);
        $deleted = 0;

        $expiredActions = $this->terminalTaskQuery($auditCutoff)
            ->distinct()->pluck('action')->all();
        foreach ($expiredActions as $action) {
            $count = $this->deletePurgeInChunks(
                fn () => $this->terminalTaskQuery($auditCutoff, (string) $action),
                "terminal tasks action=$action",
            );
            $deleted += $count;
            $this->info("Purged $count terminal tasks action=$action");
        }

        foreach (self::TASK_DIAGNOSTIC_ACTIONS as $action) {
            $count = $this->deletePurgeInChunks(
                fn () => Task::whereIn('status', ['successful', 'failed'])
                    ->where('action', $action)
                    ->whereRaw('COALESCE(last_execute_at, created_at) < ?', [$fullCutoff])
                    ->whereRaw('COALESCE(last_execute_at, created_at) >= ?', [$auditCutoff]),
                "terminal tasks action=$action",
            );
            $deleted += $count;
            if ($count > 0) {
                $this->info("Purged $count terminal tasks action=$action");
            }
        }

        $unknown = Task::whereIn('status', ['successful', 'failed'])
            ->whereNotIn('action', array_merge(self::TASK_AUDIT_ACTIONS, self::TASK_DIAGNOSTIC_ACTIONS))
            ->whereRaw('COALESCE(last_execute_at, created_at) < ?', [$fullCutoff])
            ->whereRaw('COALESCE(last_execute_at, created_at) >= ?', [$auditCutoff])
            ->selectRaw('action, COUNT(*) AS aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');
        foreach ($unknown as $action => $count) {
            $this->warn("Unclassified terminal task action=$action count=$count");
        }

        $this->info("Purged $deleted terminal tasks");
    }

    private function terminalTaskQuery($cutoff, ?string $action = null): Builder
    {
        $query = Task::whereIn('status', ['successful', 'failed'])
            ->whereRaw('COALESCE(last_execute_at, created_at) < ?', [$cutoff]);
        if ($action !== null) {
            $query->where('action', $action);
            $this->excludeProtectedPendingCommit($query, $action);
        }

        return $query;
    }

    private function excludeProtectedPendingCommit(Builder $query, string $action): void
    {
        if ($action === 'commit') {
            $query->whereNot(fn (Builder $protected) => $protected
                ->where('status', 'failed')
                ->whereExists(fn ($orders) => $orders
                    ->selectRaw('1')
                    ->from('orders as protected_orders')
                    ->join('certs as protected_certs', 'protected_certs.id', '=', 'protected_orders.latest_cert_id')
                    ->whereColumn('protected_orders.id', 'tasks.order_id')
                    ->where('protected_certs.status', 'pending')
                    ->whereNull('protected_certs.api_id')
                    ->whereColumn('tasks.last_execute_at', '>=', 'protected_certs.created_at')));
        }

        if ($action === 'commit_acme') {
            $query->whereNot(fn (Builder $protected) => $protected
                ->where('status', 'failed')
                ->whereExists(fn ($acmes) => $acmes
                    ->selectRaw('1')
                    ->from('acmes as protected_acmes')
                    ->whereColumn('protected_acmes.id', 'tasks.order_id')
                    ->where('protected_acmes.status', 'pending')
                    ->whereNull('protected_acmes.api_id')
                    ->whereColumn('tasks.last_execute_at', '>=', 'protected_acmes.created_at')));
        }
    }

    /**
     * 清理超保留期的终态 notification 行（sent / failed）。
     *
     * 只清终态行：pending/sending 进行中永不被清。90d 保留期 >> failed 重发窗口
     * （自动 1h + 手动几天），清理 created_at<now-90d 与重发 created_at>=now-1h 时间窗零重叠。
     */
    private function purgeTerminalNotifications(): void
    {
        $cutoff = now()->subDays((int) config('purge.retention.notifications', 90));

        $query = fn () => Notification::whereIn('status', ['sent', 'failed'])
            ->where('created_at', '<', $cutoff);

        $deleted = $this->deletePurgeInChunks($query, 'terminal notifications');
        $this->info("Purged $deleted terminal notifications");
    }

    /**
     * 清理超保留期的终态订单自动部署上报记录（auto_deploy_reports）。
     *
     * 报告随订单生命周期管理：仅清「订单已终态」（latestCert 落
     * ORDER_TERMINAL_CERT_STATUSES = cancelled/revoked/renewed/reissued/expired/failed）且超保留期的历史行；
     * 仍 active（部署中）/ 在途（unpaid/pending/processing/approving/cancelling）的订单显式排除，保住审计视图。
     * 孤儿行（order 已不存在）照常按保留期清理。用户删除沿 UserDataTableRegistry 走订单链、不在此路径。
     */
    private function purgeTerminalOrderReports(): void
    {
        $cutoff = now()->subDays((int) config('purge.retention.auto_deploy_reports', 90));
        $terminal = AutoDeployReportService::ORDER_TERMINAL_CERT_STATUSES;

        $query = fn () => AutoDeployReport::where('created_at', '<', $cutoff)
            ->where(function ($q) use ($terminal) {
                $q->whereDoesntHave('order')
                    ->orWhereHas('order', fn ($o) => $o->whereHas('latestCert', fn ($c) => $c->whereIn('status', $terminal)));
            });

        $deleted = $this->deletePurgeInChunks($query, 'terminal order reports');
        $this->info("Purged $deleted terminal order reports");
    }

    /**
     * 分批删除匹配行（复用 UserDataPurger::deleteInChunks 范式：do-while + 每批独立事务 +
     * gc_collect_cycles + maxIterations 护栏）。单批 LIMIT chunk 避免单条大事务撑爆 binlog /
     * 长事务锁等待；每批独立事务在低峰 01:30 控制主从复制延迟。
     *
     * @param  callable():Builder  $query  返回新建查询（每批/计数各取一次，避免 builder 复用）
     * @return int 累计删除行数
     */
    private function deletePurgeInChunks(callable $query, string $name): int
    {
        $chunkSize = (int) config('purge.chunk', 1000);
        $total = $query()->count();
        $maxIterations = (int) ceil($total / max(1, $chunkSize)) + 10;

        $totalDeleted = 0;
        $iteration = 0;

        do {
            $iteration++;

            if ($iteration > $maxIterations) {
                $remaining = $query()->count();
                $this->warn("Purge $name aborted: 删除循环超过预期次数，还剩 $remaining 条未删除");
                break;
            }

            $deleted = DB::transaction(fn () => $query()->limit($chunkSize)->delete());
            $totalDeleted += $deleted;

            gc_collect_cycles();
        } while ($deleted > 0);

        return $totalDeleted;
    }

    /**
     * 清理已签发订单的用户上传文档（文件 + 记录），保留验证报告表单。
     */
    private function purgeIssuedOrderDocuments(): void
    {
        $documents = OrderDocument::whereHas('order', fn ($query) => $query
            ->whereHas('latestCert', fn ($q) => $q->whereNotIn('status', ['unpaid', 'pending', 'processing', 'approving', 'cancelling']))
        )->get();

        $deletedFiles = 0;
        $cleanedOrderIds = [];

        foreach ($documents as $document) {
            $fullPath = storage_path("app/$document->file_path");
            if (file_exists($fullPath)) {
                unlink($fullPath);
                $deletedFiles++;
            }
            $cleanedOrderIds[$document->order_id] = true;
        }

        $deletedRecords = $documents->isNotEmpty()
            ? OrderDocument::whereIn('id', $documents->pluck('id'))->delete()
            : 0;

        // 清理空的 verification 子目录
        foreach (array_keys($cleanedOrderIds) as $orderId) {
            $dir = storage_path("app/verification/$orderId");
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }

        // 清理孤立的 verification 子目录（无对应 order_documents 记录）
        $orphanDirs = 0;
        $baseDir = storage_path('app/verification');
        if (is_dir($baseDir)) {
            foreach (scandir($baseDir) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $dir = "$baseDir/$entry";
                if (! is_dir($dir)) {
                    continue;
                }
                if (OrderDocument::where('order_id', $entry)->exists()) {
                    continue;
                }
                // 递归删除目录及文件
                foreach (scandir($dir) as $file) {
                    if ($file !== '.' && $file !== '..') {
                        unlink("$dir/$file");
                    }
                }
                rmdir($dir);
                $orphanDirs++;
            }
        }

        $this->info("Purged $deletedRecords document records, $deletedFiles files, $orphanDirs orphan dirs");
    }

    /**
     * 判断是否在同步间隔内有过同步记录。
     */
    private function hasRecentSyncAttempt(int $orderId): bool
    {
        $threshold = now()->subHours(self::SYNC_INTERVAL_HOURS);

        return Task::where('order_id', $orderId)
            ->where('action', 'sync')
            ->where(function ($query) use ($threshold) {
                $query->where('started_at', '>=', $threshold)
                    ->orWhere('last_execute_at', '>=', $threshold);
            })
            ->exists();
    }

    /**
     * 立即执行同步并记录结果，force 模式下成功时静默返回不抛异常。
     */
    private function syncImmediately(Action $action, Order $order): bool
    {
        try {
            $action->sync($order->id, true);

            return true;
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            $status = ($result['code'] ?? 0) === 1 ? 'successful' : 'failed';
            $this->recordSyncAttempt($order->id, $result, $status);

            return $status === 'successful';
        } catch (Throwable $e) {
            $this->recordSyncAttempt($order->id, [
                'code' => 0,
                'msg' => $e->getMessage(),
            ], 'failed');

            return false;
        }
    }

    /**
     * 记录一次即时同步的结果，避免推送队列任务。
     */
    private function recordSyncAttempt(int $orderId, array $result, string $status): void
    {
        Task::create([
            'order_id' => $orderId,
            'action' => 'sync',
            'result' => $result,
            'attempts' => 1,
            'started_at' => now(),
            'last_execute_at' => now(),
            'source' => getControllerCategory(),
            'weight' => 0,
            'status' => $status,
        ]);
    }
}
