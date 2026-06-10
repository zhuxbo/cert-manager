<?php

namespace Plugins\Invoice\Controllers\User;

use App\Http\Controllers\User\BaseController;
use App\Models\Scopes\UserScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Invoice\Models\Invoice;
use Plugins\Invoice\Requests\GetIdsRequest;
use Plugins\Invoice\Requests\IndexRequest;
use Plugins\Invoice\Requests\UpdateRequest;
use Plugins\Invoice\Requests\UserStoreRequest;
use Plugins\Invoice\Services\InvoiceQuotaService;

class InvoiceController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        if ($this->guard->id()) {
            Invoice::addGlobalScope(new UserScope($this->guard->id()));
        }
    }

    /**
     * 获取发票列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Invoice::query();

        if (! empty($validated['quickSearch'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('organization', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('email', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('remark', 'like', "%{$validated['quickSearch']}%");
            });
        }
        if (! empty($validated['id'])) {
            $query->where('id', $validated['id']);
        }
        if (! empty($validated['organization'])) {
            $query->where('organization', 'like', "%{$validated['organization']}%");
        }
        if (! empty($validated['email'])) {
            $query->where('email', 'like', "%{$validated['email']}%");
        }
        if (! empty($validated['amount'])) {
            if (isset($validated['amount'][0]) && isset($validated['amount'][1])) {
                $query->whereBetween('amount', $validated['amount']);
            } elseif (isset($validated['amount'][0])) {
                $query->where('amount', '>=', $validated['amount'][0]);
            } elseif (isset($validated['amount'][1])) {
                $query->where('amount', '<=', $validated['amount'][1]);
            }
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }

        $total = $query->count();
        $items = $query->select([
            'id', 'amount', 'organization', 'email', 'status', 'remark', 'created_at',
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
     * 添加发票
     */
    public function store(UserStoreRequest $request): void
    {
        $validated = $request->validated();
        $userId = $this->guard->id();

        // 额度校验 + 创建必须原子：锁住用户行序列化同一用户的并发开票，
        // 把额度校验放进锁内，关闭"两请求都读到未超额 -> 双双创建 -> 超额"的并发窗口
        DB::transaction(function () use ($validated, $userId) {
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                $this->error('用户不存在');
            }

            $quota = InvoiceQuotaService::getQuota($userId);
            if (bccomp($quota['quota'], (string) $validated['amount'], 2) < 0) {
                $this->error("超过发票额度，当前可开票额度为 {$quota['quota']} 元");
            }

            $validated['user_id'] = $userId;
            $validated['status'] = 0;
            $invoice = Invoice::create($validated);

            if (! $invoice->exists) {
                $this->error('添加失败');
            }
        });

        $this->success();
    }

    /**
     * 获取发票资料
     */
    public function show(int $id): void
    {
        $invoice = Invoice::find($id);
        if (! $invoice) {
            $this->error('发票不存在');
        }

        $invoice->makeHidden(['user_id']);

        $this->success($invoice->toArray());
    }

    /**
     * 批量获取发票资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $invoices = Invoice::whereIn('id', $ids)->get();
        if ($invoices->isEmpty()) {
            $this->error('发票不存在');
        }

        foreach ($invoices as $invoice) {
            $invoice->makeHidden(['user_id']);
        }

        $this->success($invoices->toArray());
    }

    /**
     * 更新发票资料
     */
    public function update(UpdateRequest $request, int $id): void
    {
        $invoice = Invoice::find($id);
        if (! $invoice) {
            $this->error('发票不存在');
        }

        $validated = $request->validated();
        unset($validated['status']);
        $invoice->fill($validated);
        $invoice->save();

        $this->success();
    }

    /**
     * 删除发票
     */
    public function destroy(int $id): void
    {
        $invoice = Invoice::find($id);
        if (! $invoice) {
            $this->error('发票不存在');
        }

        $invoice->delete();
        $this->success();
    }

    /**
     * 批量删除发票
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $invoices = Invoice::whereIn('id', $ids)->get();
        if ($invoices->isEmpty()) {
            $this->error('发票不存在');
        }

        Invoice::destroy($ids);
        $this->success();
    }

    /**
     * 获取当前用户可开票额度
     */
    public function quota(): void
    {
        $this->success(InvoiceQuotaService::getQuota($this->guard->id()));
    }
}
