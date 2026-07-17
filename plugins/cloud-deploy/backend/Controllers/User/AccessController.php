<?php

namespace Plugins\CloudDeploy\Controllers\User;

use App\Http\Controllers\User\BaseController;
use App\Models\Scopes\UserScope;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Requests\AccessStoreRequest;
use Plugins\CloudDeploy\Requests\AccessUpdateRequest;
use Plugins\CloudDeploy\Requests\IndexRequest;

class AccessController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        if ($this->guard->id()) {
            CloudDeployAccess::addGlobalScope(new UserScope($this->guard->id()));
        }
    }

    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = CloudDeployAccess::query();
        if (! empty($validated['provider'])) {
            $query->where('provider', $validated['provider']);
        }
        if (! empty($validated['name'])) {
            $query->where('name', 'like', "%{$validated['name']}%");
        }
        if (! empty($validated['created_at_start'])) {
            $query->where('created_at', '>=', $validated['created_at_start']);
        }
        if (! empty($validated['created_at_end'])) {
            $query->where('created_at', '<=', $validated['created_at_end']);
        }
        if (! empty($validated['quickSearch'])) {
            $query->where('name', 'like', "%{$validated['quickSearch']}%");
        }

        $total = $query->count();
        $items = $query->select(['id', 'name', 'provider', 'created_at']) // 不含 credentials
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    public function store(AccessStoreRequest $request): void
    {
        $validated = $request->validated();
        $validated['user_id'] = $this->guard->id();

        $access = CloudDeployAccess::create($validated);
        if (! $access->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    public function show(int $id): void
    {
        $access = CloudDeployAccess::find($id);
        if (! $access) {
            $this->error('凭证不存在');
        }

        // credentials 已 hidden，不回显明文
        $this->success($access->toArray());
    }

    public function update(AccessUpdateRequest $request, int $id): void
    {
        $access = CloudDeployAccess::find($id);
        if (! $access) {
            $this->error('凭证不存在');
        }

        $validated = $request->validated();
        if (isset($validated['provider']) && $validated['provider'] !== $access->provider) {
            $this->error('云平台不可修改');
        }
        if (isset($validated['user_id']) && (int) $validated['user_id'] !== (int) $access->user_id) {
            $this->error('用户不可修改');
        }

        unset($validated['provider'], $validated['user_id']);
        // 编辑留空不覆盖原凭证（反模式 17）
        if (empty($validated['credentials'])) {
            unset($validated['credentials']);
        }
        $access->fill($validated);
        $access->save();

        $this->success();
    }

    public function destroy(int $id): void
    {
        $access = CloudDeployAccess::find($id);
        if (! $access) {
            $this->error('凭证不存在');
        }

        // 删除前若仍有 target 引用 → 拒绝（设计 §12 删除生命周期）
        $inUse = CloudDeployTarget::withoutGlobalScopes()
            ->where('access_id', $id)->exists();
        if ($inUse) {
            $this->error('该凭证仍被部署目标引用，请先删除相关目标');
        }

        $access->delete();
        $this->success();
    }
}
