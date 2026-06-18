<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\Action;
use Illuminate\Console\Command;
use Throwable;

/**
 * 同步无验证信息的处理中订单
 *
 * 处理 dcv 或 validation 为空（NULL）的 processing/approving 订单 ——
 * 这类订单（codesign/docsign/smime 等无 DCV 产品）被 schedule:validate 的
 * 「dcv 且 validation 都非空」查询排除，需独立命令兜底同步。
 * 与 schedule:validate 查询互为补集，同一订单只会被其一处理。
 * 每天 9/15/21 点执行（无 DCV 产品订单量小，无需更高频率）。
 */
class SyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedule:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync processing/approving orders without validation info (dcv/validation is null)';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // dcv 或 validation 为 NULL 的 processing/approving 订单（仅 NULL，空数组 [] 不算）
        $orders = Order::with('latestCert')
            ->whereHas('latestCert', function ($query) {
                $query->whereIn('status', ['processing', 'approving'])
                    ->where(function ($sub) {
                        $sub->whereNull('dcv')->orWhereNull('validation');
                    });
            })
            ->get();

        $this->info("待同步订单数量: {$orders->count()}");

        $action = app(Action::class);

        foreach ($orders as $order) {
            try {
                // createTask 内置 checkRepeat 幂等：已存在 executing 的同 order+sync 任务则跳过
                $action->createTask($order->id, 'sync');
                $this->info("订单 #$order->id: 已创建同步任务");
            } catch (Throwable $e) {
                $this->error("订单 #$order->id: 创建同步任务失败 - {$e->getMessage()}");
            }
        }
    }
}
