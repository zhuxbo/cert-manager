<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Callback\GetIdsRequest;
use App\Http\Requests\Callback\IndexRequest;
use App\Http\Requests\Callback\StoreRequest;
use App\Http\Requests\Callback\UpdateRequest;
use App\Models\Callback;

class CallbackController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取回调列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Callback::query();

        // 添加搜索条件
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
        }
        if (! empty($validated['url'])) {
            $query->where('url', 'like', "%{$validated['url']}%");
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
                'id', 'user_id', 'url', 'status', 'created_at',
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
     * 添加回调
     */
    public function store(StoreRequest $request): void
    {
        $callback = Callback::create($request->validated());

        if (! $callback->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取回调详情
     */
    public function show($id): void
    {
        $callback = Callback::find($id);
        if (! $callback) {
            $this->error('回调不存在');
        }

        // 隐藏 token：admin 跨用户管理回调无需查看各用户 webhook 校验密钥（防截图/日志泄漏伪造回调）
        $this->success($callback->makeHidden('token')->toArray());
    }

    /**
     * 批量获取回调详情
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $callbacks = Callback::whereIn('id', $ids)->get();
        if ($callbacks->isEmpty()) {
            $this->error('回调不存在');
        }

        $this->success($callbacks->makeHidden('token')->toArray());
    }

    /**
     * 更新回调信息
     */
    public function update(UpdateRequest $request, $id): void
    {
        $callback = Callback::find($id);
        if (! $callback) {
            $this->error('回调不存在');
        }

        $callback->fill($request->validated());
        $callback->save();

        $this->success();
    }

    /**
     * 删除回调
     */
    public function destroy($id): void
    {
        $callback = Callback::find($id);
        if (! $callback) {
            $this->error('回调不存在');
        }

        $callback->delete();
        $this->success();
    }

    /**
     * 批量删除回调
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $callbacks = Callback::whereIn('id', $ids)->get();
        if ($callbacks->isEmpty()) {
            $this->error('回调不存在');
        }

        Callback::destroy($ids);
        $this->success();
    }
}
