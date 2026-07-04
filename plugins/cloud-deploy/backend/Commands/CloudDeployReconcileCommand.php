<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployTarget;

/**
 * 对账 sweep：事件驱动的最终一致兜底。补两类——
 *  A 漏推（每天）：last_cert_id != order.latest_cert_id（根本没针对当前证书推过）；
 *  B 失败节流补偿（7 天一次）：last_status=failed 且 last_deployed_at 超 7 天（瞬态失败超 tries 窗口）。
 * 不含“last_status!=success”作主条件——否则确定性失败（SM2/缺私钥）每天被重扫。
 */
class CloudDeployReconcileCommand extends Command
{
    protected $signature = 'cloud-deploy:reconcile';

    protected $description = '对账：补推漏推/失败超期的部署目标（事件驱动兜底）';

    public function handle(): int
    {
        // join orders 取 latest_cert_id + certs 取 status；console 无 scope，扫全租户（系统级对账）
        $rows = CloudDeployTarget::withoutGlobalScopes()
            ->where('cloud_deploy_targets.enabled', true)
            ->join('orders', 'orders.id', '=', 'cloud_deploy_targets.order_id')
            ->join('certs', 'certs.id', '=', 'orders.latest_cert_id')
            ->where('certs.status', 'active')
            ->where(function ($q) {
                $q->whereColumn('cloud_deploy_targets.last_cert_id', '!=', 'orders.latest_cert_id')   // A 漏推
                    ->orWhereNull('cloud_deploy_targets.last_cert_id')
                    ->orWhere(function ($q2) {                                                        // B 失败节流
                        $q2->where('cloud_deploy_targets.last_status', 'failed')
                            ->where('cloud_deploy_targets.last_deployed_at', '<', now()->subDays(7));
                    });
            })
            ->select('cloud_deploy_targets.id as target_id', 'orders.latest_cert_id as cert_id')
            ->get();

        $i = 0;
        foreach ($rows as $row) {
            CloudDeployJob::dispatch((int) $row->target_id, (int) $row->cert_id, 'auto')
                ->onQueue(config('queue.names.tasks'))
                ->delay(now()->addSeconds(($i % 60) * 30)); // 错峰：递增延迟降队列/上游峰值
            $i++;
        }

        Log::info('[cloud-deploy.reconcile] sweep 派发完成', ['dispatched' => $i]);
        $this->info("已派发 $i 个补推任务");

        return self::SUCCESS;
    }
}
