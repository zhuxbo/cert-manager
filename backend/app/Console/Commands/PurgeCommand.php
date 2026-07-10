<?php

namespace App\Console\Commands;

use App\Exceptions\ApiResponseException;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\CaLog;
use App\Models\ErrorLog;
use App\Models\Fund;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Task;
use App\Models\UserLog;
use App\Services\Order\Action;
use Illuminate\Console\Command;
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
                    // 取消前执行即时同步
                    if (! $this->syncImmediately($action, $order)) {
                        $this->info("Order $order->id: sync error, skip cancel");

                        continue;
                    }

                    // 刷新证书状态
                    $order->latestCert->refresh();
                    if ($order->latestCert->status !== 'processing') {
                        $this->info("Order $order->id: status changed to $order->latestCert->status after sync, skip cancel");

                        continue;
                    }

                    // 仍是processing，执行取消
                    $order->latestCert->update(['status' => 'cancelling']);

                    // 删除相关任务
                    $action->deleteTask($order->id, 'commit,sync,revalidate');

                    // 创建取消任务
                    $action->createTask($order->id, 'cancel');

                    $canceledCount++;
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
