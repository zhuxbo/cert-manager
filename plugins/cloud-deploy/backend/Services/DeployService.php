<?php

namespace Plugins\CloudDeploy\Services;

use App\Exceptions\ApiResponseException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployTarget;

class DeployService
{
    /**
     * 共享手动推送 action：解析 target 集合 → 逐条校验 cert active → 入队 CloudDeployJob。
     *
     * @param  int|null  $orderId  order_id 模式（推该订单全部 enabled target）；**crossUser=true 时必须为 null**（admin 只走 target_ids），否则抛 ApiResponseException
     * @param  array<int,int>  $targetIds  target_ids 模式（按 id 直查，不过滤 enabled）
     * @param  bool  $force  透传 CloudDeployJob（手动推送一律 true，绕过幂等短路）
     * @param  bool  $crossUser  true=admin 跨用户（withoutGlobalScopes 直查、不加 user_id 过滤）；false=user（靠调用方 controller 注册的 UserScope 收敛本人）
     * @return int dispatched 计数（实际入队的 Job 数；非 active / 无 cert 的 target 不计）
     */
    public function deploy(?int $orderId, array $targetIds, bool $force, bool $crossUser): int
    {
        // 契约硬约束：admin 入口（crossUser=true）只走 target_ids，不支持 order_id 模式。
        // 否则若同时 orderId!=null 且 targetIds 为空，下面两个过滤分支都跳过 → withoutGlobalScopes 无 where
        // → get() 取回全部用户的全部 target 群发。把「admin 不走 order_id」从注释升级为函数体 fail-closed 守卫，
        // 不依赖调用方自律（当前唯一 wired 调用 Task 3.2 恒传 null，但守住后续新增入口误传）。
        if ($crossUser && $orderId !== null) {
            throw new ApiResponseException('admin 入口不支持 order_id 模式，请用 target_ids', null, null, 0);
        }
        // admin 入口必须指定非空 target_ids，否则 withoutGlobalScopes 无 where 会群发全部用户 target（与上同哲学的 fail-closed）。
        if ($crossUser && empty($targetIds)) {
            throw new ApiResponseException('admin 入口必须指定 target_ids', null, null, 0);
        }

        // crossUser=false：CloudDeployTarget::query() 继承调用方 controller 注册的 UserScope（限本人）；
        // crossUser=true：admin 入口不挂 UserScope，用 withoutGlobalScopes 直查任意用户 target（杀手场景 1）。
        $query = $crossUser
            ? CloudDeployTarget::withoutGlobalScopes()
            : CloudDeployTarget::query();

        if ($orderId !== null && ! $crossUser) {
            // Order 的 UserScope 由 api.user 中间件进程级注册（UserScope::addScopeToModels 含 Order），
            // 故此 exists() 自动限当前用户、他人 order 返回 false。安全支柱靠中间件副作用，勿删。
            // crossUser=true 不走 order_id 模式（admin widget 一律 target_ids，见 §8.3）。
            $owns = Order::query()->whereKey($orderId)->exists();
            if (! $owns) {
                throw new ApiResponseException('订单不存在', null, null, 0);
            }
            $query->where('order_id', $orderId)->where('enabled', true);
        }

        if (! empty($targetIds)) {
            $query->whereIn('id', $targetIds); // 显式选 target 不过滤 enabled
        }

        $targets = $query->get();
        if ($targets->isEmpty()) {
            throw new ApiResponseException('没有可推送的目标', null, null, 0);
        }

        $dispatched = 0;
        foreach ($targets as $target) {
            $certId = (int) Order::query()->whereKey($target->order_id)->value('latest_cert_id');
            if (! $certId) {
                continue;
            }
            $status = DB::table('certs')->where('id', $certId)->value('status');
            if ($status !== 'active') {
                continue; // 仅推 active
            }
            CloudDeployJob::dispatch($target->id, $certId, 'manual', $force)
                ->onQueue(config('queue.names.tasks'));
            $dispatched++;
        }

        return $dispatched;
    }
}
