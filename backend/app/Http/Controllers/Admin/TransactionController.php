<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Transaction\IndexRequest;
use App\Models\Transaction;

/**
 * 交易记录
 */
class TransactionController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取交易记录列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Transaction::query();

        // 添加搜索条件
        if (! empty($validated['quickSearch'])) {
            $keyword = $validated['quickSearch'];
            $query->where(function ($q) use ($keyword) {
                // transaction_id 是 bigint 关联 ID（order_id / fund_id），仅在关键词为纯数字时做等值匹配以命中索引
                if (ctype_digit((string) $keyword)) {
                    $q->orWhere('transaction_id', $keyword);
                }
                $q->orWhere('remark', 'like', "%{$keyword}%");
                $q->orWhereHas('user', function ($userQuery) use ($keyword) {
                    $userQuery->where('username', 'like', "%{$keyword}%");
                });
            });
        }
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
        }
        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (! empty($validated['transaction_id'])) {
            // 交易单号即 bigint 关联 ID，按全值等值匹配命中索引（避免前置通配 LIKE 致全表扫描）
            $query->where('transaction_id', $validated['transaction_id']);
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
                'user_id', 'type', 'transaction_id', 'amount', 'balance_before', 'balance_after', 'remark', 'created_at',
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
}
