<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\UserLevel\GetCodesRequest;
use App\Http\Requests\UserLevel\GetIdsRequest;
use App\Http\Requests\UserLevel\IndexRequest;
use App\Http\Requests\UserLevel\StoreRequest;
use App\Http\Requests\UserLevel\UpdateRequest;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\UserLevel;

class UserLevelController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取用户级别列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = UserLevel::query();

        // 添加搜索条件
        if (! empty($validated['quickSearch'])) {
            $query->where(function ($query) use ($validated) {
                $query->where('code', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('name', 'like', "%{$validated['quickSearch']}%");
            });
        }

        // 值有可能为0 所以用isset
        if (isset($validated['custom'])) {
            $query->where('custom', $validated['custom']);
        }

        if (! empty($validated['code'])) {
            $query->whereIn('code', explode(',', $validated['code']));
        }

        $total = $query->count();
        $items = $query->orderBy('custom', 'desc')
            ->orderBy('weight', 'asc')
            ->orderBy('id', 'asc')
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
     * 添加用户级别
     */
    public function store(StoreRequest $request): void
    {
        $userLevel = UserLevel::create($request->validated());

        if (! $userLevel->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取用户级别
     */
    public function show($id): void
    {
        $userLevel = UserLevel::find($id);

        if (! $userLevel) {
            $this->error('用户级别不存在');
        }

        $this->success($userLevel->toArray());
    }

    /**
     * 批量获取用户级别
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $userLevels = UserLevel::whereIn('id', $ids)->get();
        if ($userLevels->isEmpty()) {
            $this->error('用户级别不存在');
        }

        $this->success($userLevels->toArray());
    }

    /**
     * 批量获取用户级别
     */
    public function batchShowInCodes(GetCodesRequest $request): void
    {
        $codes = $request->validated('codes');

        $userLevels = UserLevel::whereIn('code', $codes)->get();
        if ($userLevels->isEmpty()) {
            $this->error('用户级别不存在');
        }

        $this->success($userLevels->toArray());
    }

    /**
     * 更新用户级别
     */
    public function update(UpdateRequest $request, $id): void
    {
        $userLevel = UserLevel::find($id);
        if (! $userLevel) {
            $this->error('用户级别不存在');
        }

        $userLevel->fill($request->validated());
        $userLevel->save();

        $this->success();
    }

    /**
     * 删除用户级别（被用户/定制级别/产品价格引用时禁止删除）
     */
    public function destroy($id): void
    {
        $userLevel = UserLevel::find($id);
        if (! $userLevel) {
            $this->error('用户级别不存在');
        }

        if ($refs = $this->referenceSummary($userLevel->code)) {
            $this->error("无法删除级别「{$userLevel->name}」：仍有 $refs 在使用");
        }

        $userLevel->delete();
        $this->success();
    }

    /**
     * 批量删除用户级别（任一被引用即整批拒绝，列出被占用级别）
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $userLevels = UserLevel::whereIn('id', $ids)->get();
        if ($userLevels->isEmpty()) {
            $this->error('用户级别不存在');
        }

        $blocked = [];
        foreach ($userLevels as $userLevel) {
            if ($refs = $this->referenceSummary($userLevel->code)) {
                $blocked[] = "「{$userLevel->name}」($refs)";
            }
        }
        if (! empty($blocked)) {
            $this->error('以下级别正在使用，无法删除：'.implode('、', $blocked));
        }

        UserLevel::destroy($ids);
        $this->success();
    }

    /**
     * 统计某用户级别（按 code）的引用情况，返回可读描述；无引用返回空串。
     *
     * 引用来源：users.level_code、users.custom_level_code、product_prices.level_code。
     * 三者均按 code 关联且无 DB 外键，故删除保护必须在应用层兜底。
     * OR 条件用闭包包裹，避免与模型全局作用域组合时的优先级问题。
     */
    private function referenceSummary(string $code): string
    {
        $userCount = User::where(function ($query) use ($code) {
            $query->where('level_code', $code)
                ->orWhere('custom_level_code', $code);
        })->count();
        $priceCount = ProductPrice::where('level_code', $code)->count();

        $parts = [];
        if ($userCount > 0) {
            $parts[] = "$userCount 个用户";
        }
        if ($priceCount > 0) {
            $parts[] = "$priceCount 条产品价格";
        }

        return implode('、', $parts);
    }
}
