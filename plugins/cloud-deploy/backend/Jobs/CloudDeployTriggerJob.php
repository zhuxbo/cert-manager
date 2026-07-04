<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\Cert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Models\CloudDeployTarget;

class CloudDeployTriggerJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    // 编排幂等（重过滤 + 重 dispatch 幂等子 Job，子 Job 各自重试）。
    // tries=5 吸收升级冻结期 SkipWhenUpgradeFrozen 的 release（release 计入 attempts，
    // tries=1 会被第二次 pop 误杀在 handle 之前）；maxExceptions=1 保证业务异常只跑一次。
    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(public int $certId) {}

    public function handle(): void
    {
        // 事务内：迁移 + 幂等过滤 + 收集；返回待 dispatch 的 (targetId)
        $toDispatch = DB::transaction(function (): array {
            $cert = Cert::find($this->certId);
            if (! $cert) {
                return [];
            }

            // DB 真值判 active（绕 Cert::retrieved 副作用）
            $status = DB::table('certs')->where('id', $this->certId)->value('status');
            if ($status !== 'active') {
                return [];
            }

            $orderId = (int) $cert->order_id;

            // 续费跟随：renew cert 经 last_cert_id 回溯原订单，迁移其所有 target 到新订单
            if ($cert->action === 'renew' && $cert->last_cert_id) {
                $prevOrderId = (int) DB::table('certs')->where('id', $cert->last_cert_id)->value('order_id');
                if ($prevOrderId && $prevOrderId !== $orderId) {
                    CloudDeployTarget::withoutGlobalScopes()
                        ->where('order_id', $prevOrderId)
                        ->lockForUpdate()
                        ->update(['order_id' => $orderId]);
                }
            }

            $ids = [];
            $targets = CloudDeployTarget::withoutGlobalScopes()
                ->where('order_id', $orderId)->where('enabled', true)->get();
            foreach ($targets as $t) {
                if ((int) $t->last_cert_id === (int) $cert->id && $t->last_status === 'success') {
                    continue; // 幂等
                }
                $ids[] = $t->id;
            }

            return $ids;
        });

        // 事务外 fan-out（DB::transaction 返回即已提交，规避 after_commit=false）
        foreach ($toDispatch as $targetId) {
            CloudDeployJob::dispatch($targetId, $this->certId, 'auto')
                ->onQueue(config('queue.names.tasks'));
        }
    }
}
