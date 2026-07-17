<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Jobs\CloudDeployTriggerJob;
use Plugins\CloudDeploy\Models\CloudDeployTarget;

/**
 * 对账 sweep：事件驱动的最终一致兜底。补三类——
 *  A 漏推（每天）：last_cert_id != order.latest_cert_id（根本没针对当前证书推过）；
 *  B 失败节流补偿（7 天一次）：last_status=failed 且 last_deployed_at 超 7 天（瞬态失败超 tries 窗口）。
 *  C 续费链 fallback（每天）：TriggerJob 迁移单点失败时，target 仍锚旧订单（旧 cert 已 renewed 非 active），
 *    A/B 被 join active 过滤挡死 → 永久盲区。从 cloud_deploy_targets 小表回溯续费链派 TriggerJob 迁移 + 推送。
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
        $directCount = $i;

        // C 续费链 fallback：从 cloud_deploy_targets 小表驱动回溯续费链（全走索引：targets.order_id 索引 →
        // certs.last_cert_id unique 点查，action 仅作已定位行的过滤非驱动扫描），越权守卫 o_new.user_id =
        // target.user_id；按 c_new 去重，派 TriggerJob（迁移旧订单所有 target 到新订单 + fan-out 推送）。
        $renewCertIds = CloudDeployTarget::withoutGlobalScopes()
            ->where('cloud_deploy_targets.enabled', true)
            ->join('orders as o_old', 'o_old.id', '=', 'cloud_deploy_targets.order_id')
            ->join('certs as c_old', function ($j) {
                $j->on('c_old.id', '=', 'o_old.latest_cert_id')->where('c_old.status', '=', 'renewed');
            })
            ->join('certs as c_new', function ($j) {
                $j->on('c_new.last_cert_id', '=', 'c_old.id')
                    ->where('c_new.status', '=', 'active')
                    ->where('c_new.action', '=', 'renew');
            })
            ->join('orders as o_new', 'o_new.id', '=', 'c_new.order_id')
            ->whereColumn('o_new.user_id', 'cloud_deploy_targets.user_id') // 越权守卫：同 user 才迁移
            ->distinct()
            ->pluck('c_new.id');

        foreach ($renewCertIds as $cNewId) {
            CloudDeployTriggerJob::dispatch((int) $cNewId)
                ->onQueue(config('queue.names.tasks'))
                ->delay(now()->addSeconds(($i % 60) * 30)); // 错峰：与 A/B 段共享连续递增 $i
            $i++;
        }
        $renewCount = $i - $directCount;

        Log::info('[cloud-deploy.reconcile] sweep 派发完成', ['dispatched' => $directCount, 'renew_fallback' => $renewCount]);
        $this->info("已派发 $directCount 个补推任务 + $renewCount 个续费迁移任务");

        return self::SUCCESS;
    }
}
