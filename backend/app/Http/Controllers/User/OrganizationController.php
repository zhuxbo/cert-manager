<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ResolvesContactId;
use App\Http\Requests\Organization\GetIdsRequest;
use App\Http\Requests\Organization\IndexRequest;
use App\Http\Requests\Organization\StoreRequest;
use App\Http\Requests\Organization\UpdateRequest;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OrganizationController extends BaseController
{
    use ResolvesContactId;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取组织列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Organization::query();

        // 添加搜索条件
        if (! empty($validated['quickSearch'])) {
            // 用闭包包裹 quickSearch 的 OR 组，避免与后续附加过滤（registration_number/country/created_at 等）的 AND 条件因运算符优先级被短路
            $query->where(function ($q) use ($validated) {
                $q->where('name', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('registration_number', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('phone', 'like', "%{$validated['quickSearch']}%");
            });
        }
        if (! empty($validated['name'])) {
            $query->where('name', 'like', "%{$validated['name']}%");
        }
        if (! empty($validated['registration_number'])) {
            $query->where('registration_number', 'like', "%{$validated['registration_number']}%");
        }
        if (! empty($validated['country'])) {
            $query->where('country', 'like', "%{$validated['country']}%");
        }
        if (! empty($validated['phone'])) {
            $query->where('phone', 'like', "%{$validated['phone']}%");
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }

        $total = $query->count();
        $items = $query->select(['id', 'name', 'registration_number', 'country', 'phone', 'created_at'])
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
     * 添加组织
     */
    public function store(StoreRequest $request): void
    {
        $data = $request->validated();
        $userId = $this->guard->id();

        $organization = null;
        DB::transaction(function () use ($data, $userId, &$organization) {
            $contactId = $this->resolveContactId($data, $userId);

            $orgData = collect($data)->except(['contact', 'contact_id'])->all();
            $organization = Organization::create(array_merge($orgData, [
                'user_id' => $userId,
                'contact_id' => $contactId,
            ]));
        });

        $this->success($organization->load('contact')->toArray());
    }

    /**
     * 获取组织资料
     */
    public function show($id): void
    {
        $organization = Organization::find($id);
        if (! $organization) {
            $this->error('组织不存在');
        }

        $organization->makeHidden(['user_id']);

        $this->success($organization->toArray());
    }

    /**
     * 批量获取组织资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $organizations = Organization::whereIn('id', $ids)->get();
        if ($organizations->isEmpty()) {
            $this->error('组织不存在');
        }

        foreach ($organizations as $organization) {
            $organization->makeHidden(['user_id']);
        }

        $this->success($organizations->toArray());
    }

    /**
     * 更新组织资料
     */
    public function update(UpdateRequest $request, $id): void
    {
        $data = $request->validated();
        $userId = $this->guard->id();

        $organization = Organization::find($id);
        if (! $organization) {
            $this->error('组织不存在');
        }

        DB::transaction(function () use ($data, $userId, $organization) {
            // 未传 contact_id/contact 时保留原绑定；显式传 null 才清空。
            $contactId = array_key_exists('contact_id', $data) || array_key_exists('contact', $data)
                ? $this->resolveContactId($data, $userId)
                : $organization->contact_id;

            $orgData = collect($data)->except(['contact', 'contact_id'])->all();
            $organization->fill(array_merge($orgData, [
                'user_id' => $userId,
                'contact_id' => $contactId,
            ]));
            $organization->save();
        });

        $this->success($organization->refresh()->load('contact')->toArray());
    }

    /**
     * 删除组织
     */
    public function destroy($id): void
    {
        $organization = Organization::find($id);
        if (! $organization) {
            $this->error('组织不存在');
        }

        $organization->delete();
        $this->success();
    }

    /**
     * 批量删除组织
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $organizations = Organization::whereIn('id', $ids)->get();
        if ($organizations->isEmpty()) {
            $this->error('组织不存在');
        }

        Organization::destroy($ids);
        $this->success();
    }
}
