<?php

namespace App\Console\Commands;

use App\Exceptions\ApiResponseException;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\AutoDeployReport;
use App\Models\CallbackLog;
use App\Models\CaLog;
use App\Models\ErrorLog;
use App\Models\Fund;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Task;
use App\Models\UserLog;
use App\Services\Order\Action;
use App\Services\Order\AutoDeployReportService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PurgeCommand extends Command
{
    private const SYNC_INTERVAL_HOURS = 24;

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
    protected $description = 'Purge cache, logs, or other unnecessary data';

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $this->info(get_system_setting('site', 'name', 'SSL证书管理系统'));

        // 清理超过24小时的未支付充值
        $result = Fund::where('created_at', '<', now()->subHours(24))->where('status', 0)->delete();
        $this->info("Purged $result fund records");

        // 日志保留期 / GET-only 短保留期（config('logs.retention.*') 优先，env 兜底）
        $retentionApi = (int) config('logs.retention.api', 180);
        $retentionAdmin = (int) config('logs.retention.admin', 180);
        $retentionUser = (int) config('logs.retention.user', 180);
        $retentionCallback = (int) config('logs.retention.callback', 180);
        $retentionCa = (int) config('logs.retention.ca', 180);
        $retentionError = (int) config('logs.retention.error', 90);
        $retentionGet = (int) config('logs.retention.get_only', 30);

        // 清理过期的接口日志
        $result = ApiLog::where('created_at', '<', now()->subDays($retentionApi))->delete();
        $this->info("Purged $result API logs");

        // 清理过期的 GET 方法接口日志
        $result = ApiLog::where('created_at', '<', now()->subDays($retentionGet))->where('method', 'GET')->delete();
        $this->info("Purged $result GET API logs");

        // 清理过期的管理员日志
        $result = AdminLog::where('created_at', '<', now()->subDays($retentionAdmin))->delete();
        $this->info("Purged $result admin logs");

        // 清理过期的 GET 方法管理员日志
        $result = AdminLog::where('created_at', '<', now()->subDays($retentionGet))->whereIn('method', ['GET', 'OPTIONS'])->delete();
        $this->info("Purged $result GET admin logs");

        // 清理过期的用户日志
        $result = UserLog::where('created_at', '<', now()->subDays($retentionUser))->delete();
        $this->info("Purged $result user logs");

        // 清理过期的 GET 方法用户日志
        $result = UserLog::where('created_at', '<', now()->subDays($retentionGet))->whereIn('method', ['GET', 'OPTIONS'])->delete();
        $this->info("Purged $result GET user logs");

        // 清理过期的回调日志
        $result = CallbackLog::where('created_at', '<', now()->subDays($retentionCallback))->delete();
        $this->info("Purged $result callback logs");

        // 清理过期的 CA 日志
        $result = CaLog::where('created_at', '<', now()->subDays($retentionCa))->delete();
        $this->info("Purged $result ca logs");

        // 清理过期的错误日志
        $result = ErrorLog::where('created_at', '<', now()->subDays($retentionError))->delete();
        $this->info("Purged $result error logs");

        // 动态清理其他 _logs 后缀表（插件日志表等）
        // 用 Schema::getTableListing() 替代 raw SHOW TABLES LIKE，统一走 Laravel 抽象（Laravel 11+）
        $knownLogTables = ['api_logs', 'admin_logs', 'user_logs', 'callback_logs', 'ca_logs', 'error_logs'];
        try {
            $tableNames = Schema::getTableListing();
            foreach ($tableNames as $tableName) {
                if (! is_string($tableName) || ! str_ends_with($tableName, '_logs')) {
                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                    continue;
                }
                if (in_array($tableName, $knownLogTables)) {
                    continue;
                }
                $result = DB::table($tableName)->where('created_at', '<', now()->subDays($retentionApi))->delete();
                $this->info("Purged $result $tableName");
            }
        } catch (Throwable $e) {
            $this->warn('Dynamic log cleanup failed: '.$e->getMessage());
        }

        // 清理已签发订单的用户上传文档（保留验证报告表单）
        $this->purgeIssuedOrderDocuments();

        // 清理 storage/temp-certs 下超过 1 小时的残留（下载中断/异常/exit 未清理的临时证书目录，含私钥）
        $this->purgeStaleTempCerts();

        // 清理超保留期的终态运行时表行（对账痕迹 tasks / 交付记录 notifications / 自动部署上报 auto_deploy_reports）
        // 包裹与上方 _logs 清理对称：清理是次要职责，抛错不得中止后续退款期取消主流程
        try {
            $this->purgeTerminalTasks();
            $this->purgeTerminalNotifications();
            $this->purgeTerminalOrderReports();
        } catch (Throwable $e) {
            $this->warn('Terminal rows cleanup failed: '.$e->getMessage());
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

    /**
     * 清理超保留期的终态 task 行（successful / failed）。
     *
     * 只清终态历史行：清理集 {successful,failed} 与业务锁定集 {executing,stopped}
     * （Task::scopeLockForMutation / deleteTask 的锁定/删除集）完全不相交，DELETE 不与任何
     * 持 task 锁的业务路径争同一行、无锁序义务。failed 可被 admin batchStart 复活，
     * 但 DELETE 与 batchStart(failed→executing) 由 InnoDB 行锁串行、90d 窗口远大于人工重试窗口。
     *
     * 例外：关联订单仍为 pending 卡单（latestCert.status=pending）的终态 task **不清**——其 failed commit
     * task 是卡单对账「到顶」判据（PendingReconcileQuery::MAXED_COUNT_SUBQUERY，锚 latestCert.created_at、
     * 下界无上界）的计数集，被 created_at>90d 删掉会让计数归零 → 订单重回 actionable → reconcile 重打上游
     * + 重发去重通知（reconcile_user_alerted_at 随 task.result 删丢失）。订单收尾（cancelled/active/renewed
     * 等非 pending）后其历史 task 正常清理、不永久堆积；孤儿 task（order 不存在）照常清理。
     */
    private function purgeTerminalTasks(): void
    {
        $cutoff = now()->subDays((int) config('purge.retention.tasks', 90));

        $query = fn () => Task::whereIn('status', ['successful', 'failed'])
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('order', fn ($q) => $q->whereHas('latestCert', fn ($c) => $c->where('status', 'pending')));

        $deleted = $this->deletePurgeInChunks($query, 'terminal tasks');
        $this->info("Purged $deleted terminal tasks");
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
     * 长事务锁等待；每批独立事务在低峰 02:00 控制主从复制延迟。
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
