<?php

namespace App\Http\Controllers\User;

use App\Http\Requests\AutoDeployReport\IndexRequest;
use App\Models\AutoDeployReport;

class AutoDeployReportController extends BaseController
{
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = AutoDeployReport::query()
            ->whereHas('order', fn ($order) => $order->where('user_id', $this->guard->id()));

        if (! empty($validated['quickSearch'])) {
            $keyword = $validated['quickSearch'];
            $query->where(function ($query) use ($keyword) {
                $query->where('ip', 'like', "%{$keyword}%")
                    ->orWhere('message', 'like', "%{$keyword}%")
                    ->orWhereHas('cert', fn ($cert) => $cert->where('common_name', 'like', "%{$keyword}%"));

                if (ctype_digit($keyword)) {
                    $query->orWhere('order_id', (int) $keyword)
                        ->orWhere('cert_id', (int) $keyword);
                }
            });
        }

        foreach (['order_id', 'status', 'ip'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (! empty($validated['time'])) {
            $query->where(function ($query) use ($validated) {
                $query->where(function ($query) use ($validated) {
                    $query->whereNotNull('deployed_at')->whereBetween('deployed_at', $validated['time']);
                })->orWhere(function ($query) use ($validated) {
                    $query->whereNull('deployed_at')->whereBetween('created_at', $validated['time']);
                });
            });
        }

        $total = $query->count();
        $items = $query->with('cert:id,common_name')
            ->orderByDesc('id')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success(compact('items', 'total', 'pageSize', 'currentPage'));
    }
}
