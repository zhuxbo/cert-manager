<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\DeployToken\GetIdsRequest;
use App\Http\Requests\DeployToken\IndexRequest;
use App\Http\Requests\DeployToken\StoreRequest;
use App\Http\Requests\DeployToken\UpdateRequest;
use App\Models\DeployToken;

class DeployTokenController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取部署令牌列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = DeployToken::query();

        // 添加搜索条件
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $total = $query->count();
        $items = $query->with([
            'user' => function ($query) {
                $query->select(['id', 'username']);
            },
        ])
            ->select([
                'id', 'user_id', 'allowed_ips', 'rate_limit', 'status', 'last_used_at', 'last_used_ip', 'created_at',
            ])
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

    /**
     * 添加部署令牌
     */
    public function store(StoreRequest $request): void
    {
        $deployToken = DeployToken::create($request->validated());

        if (! $deployToken->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取部署令牌详情
     */
    public function show($id): void
    {
        $deployToken = DeployToken::find($id);
        if (! $deployToken) {
            $this->error('部署令牌不存在');
        }

        // 隐藏 token 明文：admin 跨用户管理部署令牌无需查看他人高权限部署凭据（与 ApiToken 不可见基线一致）
        $this->success($deployToken->makeHidden('token')->toArray());
    }

    /**
     * 批量获取部署令牌详情
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $deployTokens = DeployToken::whereIn('id', $ids)->get();
        if ($deployTokens->isEmpty()) {
            $this->error('部署令牌不存在');
        }

        $this->success($deployTokens->makeHidden('token')->toArray());
    }

    /**
     * 更新部署令牌信息
     */
    public function update(UpdateRequest $request, $id): void
    {
        $deployToken = DeployToken::find($id);
        if (! $deployToken) {
            $this->error('部署令牌不存在');
        }

        $deployToken->fill($request->validated());
        $deployToken->save();

        $this->success();
    }

    /**
     * 删除部署令牌
     */
    public function destroy($id): void
    {
        $deployToken = DeployToken::find($id);
        if (! $deployToken) {
            $this->error('部署令牌不存在');
        }

        $deployToken->delete();
        $this->success();
    }

    /**
     * 批量删除部署令牌
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $deployTokens = DeployToken::whereIn('id', $ids)->get();
        if ($deployTokens->isEmpty()) {
            $this->error('部署令牌不存在');
        }

        DeployToken::destroy($ids);
        $this->success();
    }
}
