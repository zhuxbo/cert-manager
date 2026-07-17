<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiResponseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\GetIdsRequest;
use App\Http\Requests\Task\IndexRequest;
use App\Jobs\TaskJob;
use App\Models\Task;
use App\Services\Acme\Action as AcmeAction;
use App\Services\Order\Action;

class TaskController extends Controller
{
    /**
     * 获取任务列表
     */
    public function index(IndexRequest $request)
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Task::query();

        if (isset($validated['order_id'])) {
            $query->where('order_id', $validated['order_id']);
        }
        if (isset($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (isset($validated['source'])) {
            $query->where('source', 'like', "%{$validated['source']}%");
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }

        $total = $query->count();
        $tasks = $query->select([
            'id', 'order_id', 'action', 'attempts', 'source', 'weight', 'status', 'started_at', 'last_execute_at', 'created_at',
        ])
            ->orderBy('weight', 'desc')
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success([
            'items' => $tasks,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    /**
     * 获取任务详情
     */
    public function show($id)
    {
        $task = Task::find($id);
        if (! $task) {
            $this->error('任务不存在');
        }

        $this->success($task->toArray());
    }

    /**
     * 删除任务
     */
    public function destroy($id)
    {
        $task = Task::find($id);
        if (! $task) {
            $this->error('任务不存在');
        }

        $task->delete();

        $this->success();
    }

    /**
     * 批量删除任务
     */
    public function batchDestroy(GetIdsRequest $request)
    {
        $ids = $request->validated('ids');

        $tasks = Task::whereIn('id', $ids)->get();
        if ($tasks->isEmpty()) {
            $this->error('任务不存在');
        }

        Task::destroy($ids);

        $this->success();
    }

    /**
     * 执行任务
     * 只有 commit revalidate sync cancel cancel_acme callback delegation 可以加入任务队列
     */
    public function batchExecute(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $tasks = Task::whereIn('id', $ids)
            ->where('status', 'executing')
            ->get();
        if ($tasks->isEmpty()) {
            $this->error('任务不存在或已完成');
        }

        foreach ($tasks as $task) {
            $action = $task->action;
            try {
                if (in_array($action, ['cancel_acme', 'commit_acme', 'sync_acme'], true)) {
                    $method = str_replace('_acme', '', $action);
                    $acmeAction = new AcmeAction;
                    if (! method_exists($acmeAction, $method)) {
                        throw new ApiResponseException("AcmeAction::$method 方法不存在");
                    }
                    $acmeAction->$method($task->order_id);
                } else {
                    $orderAction = new Action;
                    if (! method_exists($orderAction, $action)) {
                        throw new ApiResponseException("Action::$action 方法不存在");
                    }
                    $orderAction->$action($task->order_id);
                }
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                $data['result'] = $result;
                $data['attempts'] = ($task['attempts'] ?? 0) + 1;

                if ($result['code'] === 1) {
                    $data['status'] = 'successful';
                } else {
                    $data['status'] = 'failed';
                }

                $data['last_execute_at'] = now();
                $data['weight'] = 0;
                $task->update($data);
            }
        }

        $this->success();
    }

    /**
     * 启动任务
     */
    public function batchStart(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $tasks = Task::whereIn('id', $ids)
            ->whereIn('status', ['stopped', 'failed'])
            ->get();
        if ($tasks->isEmpty()) {
            $this->error('任务不存在或已启动');
        }

        foreach ($tasks as $task) {
            $isCancel = in_array($task->action, ['cancel', 'cancel_acme']);
            $data = ['status' => 'executing', 'weight' => $task->id];
            $data['started_at'] = $isCancel ? now()->addSeconds(120) : now();
            $task->update($data);

            $job = TaskJob::dispatch(['id' => $task->id])->afterCommit()->onQueue(config('queue.names.tasks'));
            if ($isCancel) {
                // T3：cancel/cancel_acme 恢复必须补 delay，否则 job 立即消费、handle 守卫 started_at<=now 落空
                // no-op → task 永久 executing（用户取消意图静默失效）。delay 跟随 started_at + 3s 缓冲（全仓惯例：
                // 队列定时比可执行时间多 3 秒），从 $task->started_at 取真值，消除与 :175 的 120 硬编码双点漂移。
                $job->delay($task->started_at->copy()->addSeconds(3));
            }
        }

        $this->success();
    }

    /**
     * 停止任务
     */
    public function batchStop(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $stopped = Task::whereIn('id', $ids)
            ->where('status', 'executing')
            ->update(['status' => 'stopped']);
        if ($stopped === 0) {
            $this->error('任务不存在或已停止');
        }

        $this->success();
    }
}
