<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugins\CloudDeploy\Models\CloudDeployTarget;

class CloudChainBackfillJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    // 幂等（重查 failed target + 重 dispatch 幂等子 Job）。tries=5 吸收升级冻结期
    // SkipWhenUpgradeFrozen 的 release（release 计入 attempts，tries=1 会被第二次 pop
    // 误杀在 handle 之前）；maxExceptions=1 保证业务异常只跑一次。
    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(public string $issuer) {}

    public function handle(): void
    {
        // 找：last_status=failed 的 enabled target，其订单 latest cert 现 active 且 issuer == 本次补全的
        $rows = CloudDeployTarget::withoutGlobalScopes()
            ->where('cloud_deploy_targets.last_status', 'failed')
            ->where('cloud_deploy_targets.enabled', true)
            ->join('orders', 'orders.id', '=', 'cloud_deploy_targets.order_id')
            ->join('certs', 'certs.id', '=', 'orders.latest_cert_id')
            ->where('certs.issuer', $this->issuer)
            ->where('certs.status', 'active')
            ->select(['cloud_deploy_targets.id as target_id', 'certs.id as cert_id'])
            ->get();

        foreach ($rows as $row) {
            CloudDeployJob::dispatch((int) $row->target_id, (int) $row->cert_id, 'auto')
                ->onQueue(config('queue.names.tasks'));
        }
    }
}
