<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Contact\GetIdsRequest;
use App\Http\Requests\Contact\IndexRequest;
use App\Http\Requests\Contact\StoreRequest;
use App\Http\Requests\Contact\UpdateRequest;
use App\Models\Contact;
use App\Models\Organization;

class ContactController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取联系人列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Contact::query();

        // 添加搜索条件
        if (! empty($validated['quickSearch'])) {
            $query->where(function ($q) use ($validated) {
                $q->whereHas('user', function ($userQuery) use ($validated) {
                    $userQuery->where('username', 'like', "%{$validated['quickSearch']}%");
                })
                    ->orWhere('first_name', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('last_name', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('email', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('phone', 'like', "%{$validated['quickSearch']}%");
            });
        }
        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
        }
        if (! empty($validated['first_name'])) {
            $query->where('first_name', 'like', "%{$validated['first_name']}%");
        }
        if (! empty($validated['last_name'])) {
            $query->where('last_name', 'like', "%{$validated['last_name']}%");
        }
        if (! empty($validated['email'])) {
            $query->where('email', 'like', "%{$validated['email']}%");
        }
        if (! empty($validated['phone'])) {
            $query->where('phone', 'like', "%{$validated['phone']}%");
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }

        $total = $query->count();
        $items = $query->with([
            'user' => function ($query) {
                $query->select(['id', 'username']);
            },
        ])
            ->select([
                'id', 'user_id', 'first_name', 'last_name', 'identification_number', 'title', 'email', 'phone', 'created_at',
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
     * 添加联系人
     */
    public function store(StoreRequest $request): void
    {
        $contact = Contact::create($request->validated());

        if (! $contact->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取联系人资料
     */
    public function show($id): void
    {
        $contact = Contact::find($id);
        if (! $contact) {
            $this->error('联系人不存在');
        }

        $this->success($contact->toArray());
    }

    /**
     * 批量获取联系人资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $contacts = Contact::whereIn('id', $ids)->get();
        if ($contacts->isEmpty()) {
            $this->error('联系人不存在');
        }

        $this->success($contacts->toArray());
    }

    /**
     * 更新联系人资料
     */
    public function update(UpdateRequest $request, $id): void
    {
        $contact = Contact::find($id);
        if (! $contact) {
            $this->error('联系人不存在');
        }

        $contact->fill($request->validated());
        $contact->save();

        $this->success();
    }

    /**
     * 删除联系人
     */
    public function destroy($id): void
    {
        $contact = Contact::find($id);
        if (! $contact) {
            $this->error('联系人不存在');
        }

        $refs = Organization::where('contact_id', $id)->pluck('name')->all();
        if (! empty($refs)) {
            $this->error('该联系人正被以下企业绑定：'.implode('、', $refs));
        }

        $contact->delete();
        $this->success();
    }

    /**
     * 批量删除联系人
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $contacts = Contact::whereIn('id', $ids)->get();
        if ($contacts->isEmpty()) {
            $this->error('联系人不存在');
        }

        $refs = Organization::whereIn('contact_id', $ids)
            ->get(['contact_id', 'name'])
            ->groupBy('contact_id');
        if ($refs->isNotEmpty()) {
            $msg = $refs->map(fn ($items, $cid) => "ID $cid 被 ".$items->pluck('name')->implode('、').' 绑定')->implode('；');
            $this->error("以下联系人正被引用，整批未删除：$msg");
        }

        Contact::destroy($ids);
        $this->success();
    }
}
