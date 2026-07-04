<?php

namespace Plugins\CloudDeploy\Controllers\User;

use App\Http\Controllers\User\BaseController;
use App\Models\Scopes\UserScope;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Requests\IndexRequest;
use Plugins\CloudDeploy\Requests\TargetStoreRequest;
use Plugins\CloudDeploy\Requests\TargetUpdateRequest;
use Plugins\CloudDeploy\Support\TenantConsistency;

class TargetController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        if ($this->guard->id()) {
            CloudDeployTarget::addGlobalScope(new UserScope($this->guard->id()));
        }
    }

    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = CloudDeployTarget::query();

        if (! empty($validated['provider'])) {
            $query->whereHas('access', fn ($q) => $q->where('provider', $validated['provider']));
        }
        if (! empty($validated['product'])) {
            $query->where('product', $validated['product']);
        }
        if (! empty($validated['order_id'])) {
            $query->where('order_id', $validated['order_id']);
        }
        if (isset($validated['enabled'])) {
            $query->where('enabled', $validated['enabled']);
        }
        if (! empty($validated['last_status'])) {
            if ($validated['last_status'] === 'unpushed') {
                $query->whereNull('last_status');
            } else {
                $query->where('last_status', $validated['last_status']);
            }
        }
        if (! empty($validated['keyword'])) {
            // 证书域名：经本人 target 的 order.latestCert（依赖 CloudDeployTarget::order()，P1 提供）。
            // 用相关子查询 whereHas，不 raw join——避免 orders.user_id 抬进外层与 UserScope 的
            // 未限定 where('user_id') 撞 MySQL 1052；收敛仍靠外层 cloud_deploy_targets 的 UserScope。
            $kw = $validated['keyword'];
            $query->whereHas('order.latestCert', fn ($q) => $q->where('common_name', 'like', "%{$kw}%"));
        }
        if (! empty($validated['last_deployed_at_start'])) {
            $query->where('last_deployed_at', '>=', $validated['last_deployed_at_start']);
        }
        if (! empty($validated['last_deployed_at_end'])) {
            $query->where('last_deployed_at', '<=', $validated['last_deployed_at_end']);
        }
        if (! empty($validated['created_at_start'])) {
            $query->where('created_at', '>=', $validated['created_at_start']);
        }
        if (! empty($validated['created_at_end'])) {
            $query->where('created_at', '<=', $validated['created_at_end']);
        }
        if (! empty($validated['quickSearch'])) {
            $kw = $validated['quickSearch'];
            // orWhere 分组包裹，避免破坏外层 AND（UserScope + 其余筛选）。
            // 订单号(order_id 精确等值——与 admin quickSearch 同口径：order_id 是 snowflake 整数列、
            // 不需子串模糊，精确等值命中 order_id 索引；非数字关键词时 MySQL 隐式转 0 不命中、由 orWhere 落到域名/凭证名分支) /
            // 域名(order.latestCert common_name) / 凭证名(access.name)。
            $query->where(function ($w) use ($kw) {
                $w->where('order_id', $kw)
                    ->orWhereHas('order.latestCert', fn ($q) => $q->where('common_name', 'like', "%{$kw}%"))
                    ->orWhereHas('access', fn ($q) => $q->where('name', 'like', "%{$kw}%"));
            });
        }

        $total = $query->count();
        $items = $query->with('access:id,provider')
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)->limit($pageSize)->get();

        // 把 access.provider 拍平为顶层 provider（前端列表「云平台·产品」列直接读 row.provider；
        // target 表无 provider 列，经 access 关系取——eager-load 防 N+1）。
        $items->transform(function (CloudDeployTarget $t) {
            $t->setAttribute('provider', $t->access?->provider);

            return $t;
        });

        $this->success([
            'items' => $items, 'total' => $total,
            'pageSize' => $pageSize, 'currentPage' => $currentPage,
        ]);
    }

    public function store(TargetStoreRequest $request): void
    {
        $validated = $request->validated();
        $userId = $this->guard->id();

        if (! TenantConsistency::check($userId, (int) $validated['access_id'], (int) $validated['order_id'])) {
            $this->error('凭证或订单不属于当前用户');
        }

        $validated['user_id'] = $userId;
        $target = CloudDeployTarget::create($validated);
        if (! $target->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    public function show(int $id): void
    {
        $target = CloudDeployTarget::find($id);
        if (! $target) {
            $this->error('目标不存在');
        }
        $this->success($target->toArray());
    }

    public function update(TargetUpdateRequest $request, int $id): void
    {
        $target = CloudDeployTarget::find($id);
        if (! $target) {
            $this->error('目标不存在');
        }

        $validated = $request->validated();
        // 改 access 时同样校验归属（order 不可改）
        if (isset($validated['access_id'])
            && ! TenantConsistency::check($this->guard->id(), (int) $validated['access_id'], null)) {
            $this->error('凭证不属于当前用户');
        }

        $target->fill($validated);
        $target->save();

        $this->success();
    }

    public function destroy(int $id): void
    {
        $target = CloudDeployTarget::find($id);
        if (! $target) {
            $this->error('目标不存在');
        }
        $target->delete();
        $this->success();
    }
}
