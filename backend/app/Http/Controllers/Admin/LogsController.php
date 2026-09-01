<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Logs\LogsApiRequest;
use App\Http\Requests\Logs\LogsCallbackRequest;
use App\Http\Requests\Logs\LogsCaRequest;
use App\Http\Requests\Logs\LogsErrorRequest;
use App\Http\Requests\Logs\LogsWebRequest;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\CaLog;
use App\Models\ErrorLog;
use App\Models\UserLog;

class LogsController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get(string $type, int $id): void
    {
        $model = match ($type) {
            'admin' => AdminLog::with(['admin' => function ($query) {
                $query->select(['id', 'username']);
            }])->find($id),
            'user' => UserLog::with(['user' => function ($query) {
                $query->select(['id', 'username']);
            }])->find($id),
            'api' => ApiLog::with(['user' => function ($query) {
                $query->select(['id', 'username']);
            }])->find($id),
            'callback' => CallbackLog::find($id),
            'ca' => CaLog::find($id),
            'error' => ErrorLog::find($id),
            default => null,
        };

        if (! $model) {
            $this->error('日志不存在');
        }

        $this->success($model->toArray());
    }

    public function admin(LogsWebRequest $request): void
    {
        $fields = ['id', 'url', 'status', 'created_at', 'method', 'admin_id', 'module', 'action', 'status_code', 'duration', 'ip'];
        $this->logsQuery(AdminLog::query()->select($fields), 'admin', $request);
    }

    public function user(LogsWebRequest $request): void
    {
        $fields = ['id', 'url', 'status', 'created_at', 'method', 'user_id', 'module', 'action', 'status_code', 'duration', 'ip'];
        $this->logsQuery(UserLog::query()->select($fields), 'user', $request);
    }

    public function api(LogsApiRequest $request): void
    {
        $fields = ['id', 'module', 'action', 'url', 'status', 'created_at', 'method', 'user_id', 'version', 'status_code', 'duration', 'ip'];
        $this->logsQuery(ApiLog::query()->select($fields), 'user', $request);
    }

    public function callback(LogsCallbackRequest $request): void
    {
        $fields = ['id', 'module', 'action', 'url', 'status', 'created_at', 'method', 'ip'];
        $this->logsQuery(CallbackLog::query()->select($fields), '', $request);
    }

    public function ca(LogsCaRequest $request): void
    {
        $fields = ['id', 'url', 'status', 'created_at', 'api', 'status_code', 'duration'];
        $this->logsQuery(CaLog::query()->select($fields), '', $request);
    }

    public function errors(LogsErrorRequest $request): void
    {
        $fields = ['id', 'module', 'action', 'url', 'method', 'created_at', 'status_code', 'exception', 'message', 'ip'];
        $this->logsQuery(ErrorLog::query()->select($fields), '', $request);
    }

    private function logsQuery($query, string $relation, $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $this->applyFilters($query, $validated);

        $total = $query->count();

        $items = $query->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->orderBy('id', 'desc')
            ->get();

        // 获取数据后再加载关联
        if ($relation) {
            $items->load([
                $relation => function ($query) {
                    $query->select(['id', 'username']);
                },
            ]);
        }

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    private function applyFilters($query, array $validated): void
    {
        // 如果有用户名搜索，只需要查询单个用户ID
        if (! empty($validated['admin_id'])) {
            $query->where('admin_id', $validated['admin_id']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['callback_id'])) {
            $query->where('callback_id', $validated['callback_id']);
        }

        if (! empty($validated['url'])) {
            $query->where('url', 'like', "%{$validated['url']}%");
        }

        if (! empty($validated['module'])) {
            $query->where('module', $validated['module']);
        }

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (! empty($validated['version'])) {
            $query->where('version', $validated['version']);
        }

        if (! empty($validated['method'])) {
            $query->where('method', $validated['method']);
        }

        if (! empty($validated['api'])) {
            $query->where('api', $validated['api']);
        }

        if (! empty($validated['exception'])) {
            $query->where('exception', 'like', "%{$validated['exception']}%");
        }

        if (! empty($validated['message'])) {
            $query->where('message', 'like', "%{$validated['message']}%");
        }

        if (! empty($validated['trace'])) {
            $query->where('trace', 'like', "%{$validated['trace']}%");
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

        if (! empty($validated['status_code'])) {
            $query->where('status_code', $validated['status_code']);
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
