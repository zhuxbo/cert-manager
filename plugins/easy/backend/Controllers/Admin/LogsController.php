<?php

namespace Plugins\Easy\Controllers\Admin;

use App\Http\Controllers\Admin\BaseController;
use Plugins\Easy\Models\EasyLog;
use Plugins\Easy\Requests\LogsEasyRequest;

class LogsController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function easy(LogsEasyRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = EasyLog::query()->select(['id', 'action', 'url', 'status', 'created_at', 'method', 'ip']);

        $this->applyFilters($query, $validated);

        $total = $query->count();

        $items = $query->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->orderBy('id', 'desc')
            ->get();

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    public function get(int $id): void
    {
        $model = EasyLog::find($id);

        if (! $model) {
            $this->error('日志记录不存在');
        }

        $this->success($model->toArray());
    }

    private function applyFilters($query, array $validated): void
    {
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (! empty($validated['url'])) {
            $query->where('url', 'like', "%{$validated['url']}%");
        }

        if (! empty($validated['method'])) {
            $query->where('method', $validated['method']);
        }

        if (! empty($validated['params'])) {
            $query->where('params', 'like', "%{$validated['params']}%");
        }

        if (! empty($validated['response'])) {
            $query->where('response', 'like', "%{$validated['response']}%");
        }

        if (! empty($validated['ip'])) {
            $query->where('ip', 'like', "%{$validated['ip']}%");
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', [
                $validated['created_at'][0],
                $validated['created_at'][1],
            ]);
        }
    }
}
