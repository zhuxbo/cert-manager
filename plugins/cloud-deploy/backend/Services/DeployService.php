<?php

namespace Plugins\CloudDeploy\Services;

use App\Exceptions\ApiResponseException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Support\TenantConsistency;

class DeployService
{
    /**
     * 共享手动推送 action：解析 target 集合 → 逐条校验 cert active → 入队 CloudDeployJob。
     *
     * @param  int|null  $orderId  order_id 模式（推该订单全部 enabled target）；crossUser=true 时必须同时提供 $userId
     * @param  array<int,int>  $targetIds  target_ids 模式（按 id 直查，不过滤 enabled）
     * @param  bool  $force  透传 CloudDeployJob（手动推送一律 true，绕过幂等短路）
     * @param  bool  $crossUser  true=admin 跨用户（target_ids 直查，order_id 模式用 $userId 收敛）；false=user（靠调用方 controller 注册的 UserScope 收敛本人）
     * @param  int|null  $userId  admin order_id 模式的用户收敛键
     * @return int dispatched 计数（实际入队的 Job 数；非 active / 无 cert 的 target 不计）
     */
    public function deploy(?int $orderId, array $targetIds, bool $force, bool $crossUser, ?int $userId = null): int
    {
        // admin order_id 模式只允许订单详情这类已知用户上下文：必须带 user_id 并按 (order_id,user_id)
        // 双键过滤；否则 withoutGlobalScopes + 空 target_ids 会退化成全量 target。
        if ($crossUser && $orderId !== null && $userId === null) {
            throw new ApiResponseException('admin order_id 模式必须指定 user_id', null, null, 0);
        }
        // admin 跨用户入口必须至少有 order_id 或非空 target_ids，否则 withoutGlobalScopes 无 where 会群发全部用户 target。
        if ($crossUser && $orderId === null && empty($targetIds)) {
            throw new ApiResponseException('admin 入口必须指定 target_ids', null, null, 0);
        }

        // crossUser=false：CloudDeployTarget::query() 继承调用方 controller 注册的 UserScope（限本人）；
        // crossUser=true：admin 入口不挂 UserScope，用 withoutGlobalScopes 直查任意用户 target（杀手场景 1）。
        $query = $crossUser
            ? CloudDeployTarget::withoutGlobalScopes()
            : CloudDeployTarget::query();

        if ($orderId !== null) {
            $orderQuery = $crossUser ? Order::withoutGlobalScopes() : Order::query();
            if ($crossUser) {
                $orderQuery->where('user_id', $userId);
            }
            // user：Order 的 UserScope 由 api.user 中间件进程级注册，自动限本人。
            // admin：显式用 (order_id,user_id) 收敛订单详情的一键推送。
            $owns = $orderQuery->whereKey($orderId)->exists();
            if (! $owns) {
                throw new ApiResponseException('订单不存在', null, null, 0);
            }
            $query->where('order_id', $orderId)->where('enabled', true);
            if ($crossUser) {
                $query->where('user_id', $userId);
            }
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
            if (! TenantConsistency::check((int) $target->user_id, (int) $target->access_id, (int) $target->order_id)) {
                continue;
            }

            $certId = (int) Order::withoutGlobalScopes()->whereKey($target->order_id)->value('latest_cert_id');
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
