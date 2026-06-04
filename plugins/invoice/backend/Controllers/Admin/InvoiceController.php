<?php

namespace Plugins\Invoice\Controllers\Admin;

use App\Http\Controllers\Admin\BaseController;
use Illuminate\Support\Str;
use Plugins\Invoice\Models\Invoice;
use Plugins\Invoice\Requests\GetIdsRequest;
use Plugins\Invoice\Requests\IndexRequest;
use Plugins\Invoice\Requests\StoreRequest;
use Plugins\Invoice\Requests\UpdateExternalConfigRequest;
use Plugins\Invoice\Requests\UpdateRequest;
use Plugins\Invoice\Services\InvoiceConfig;
use Plugins\Invoice\Services\InvoiceQuotaService;

class InvoiceController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
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
                $q->whereHas('user', function ($userQuery) use ($validated) {
                    $userQuery->where('username', 'like', "%{$validated['quickSearch']}%");
                })
                    ->orWhere('organization', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('email', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('remark', 'like', "%{$validated['quickSearch']}%");
            });
        }
        if (! empty($validated['id'])) {
            $query->where('id', $validated['id']);
        }
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
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
        $items = $query->with([
            'user' => function ($query) {
                $query->select(['id', 'username']);
            },
        ])
            ->select([
                'id', 'user_id', 'amount', 'organization', 'email', 'status', 'remark', 'created_at',
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
    public function store(StoreRequest $request): void
    {
        $invoice = Invoice::create($request->validated());

        if (! $invoice->exists) {
            $this->error('添加失败');
        }

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

        $invoice->fill($request->validated());
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
     * 获取指定用户的可开票额度
     */
    public function quota(int $userId): void
    {
        $this->success(InvoiceQuotaService::getQuota($userId));
    }

    /**
     * 获取外部接入配置（token 脱敏）
     */
    public function externalConfig(): void
    {
        $token = InvoiceConfig::get('external_token');
        $hasToken = is_string($token) && $token !== '';
        $masked = $hasToken
            ? substr($token, 0, 4).str_repeat('*', max(strlen($token) - 8, 3)).substr($token, -4)
            : '';

        $this->success([
            'has_token' => $hasToken,
            'token_masked' => $masked,
            'allowed_ips' => InvoiceConfig::get('external_allowed_ips', ''),
        ]);
    }

    /**
     * 更新外部接入配置（仅 allowed_ips，不动 token）
     */
    public function updateExternalConfig(UpdateExternalConfigRequest $request): void
    {
        InvoiceConfig::set('external_allowed_ips', (string) $request->validated('allowed_ips', ''));
        $this->success();
    }

    /**
     * 重新生成外部接入 token（明文仅此一次返回）
     */
    public function regenerateExternalToken(): void
    {
        $token = Str::random(32);
        InvoiceConfig::set('external_token', $token);
        $this->success(['token' => $token]);
    }
}
