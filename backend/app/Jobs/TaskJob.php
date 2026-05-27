<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ApiResponseException;
use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\Admin;
use App\Models\Task as TaskModel;
use App\Services\Acme\Action as AcmeAction;
use App\Services\LogBuffer;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TaskJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;

    /**
     * 跨表追踪 ID。
     *
     * 序列化到 payload 是为了跨进程传递（容器单例不跨 worker 进程边界）。
     * 构造时优先继承父 request 的 correlation_id；命令行入口或独立 dispatch 时新生成 UUID。
     */
    protected string $correlationId;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->correlationId = (app()->bound('correlation_id'))
            ? (string) app('correlation_id')
            : (string) Str::uuid();
    }

    /**
     * 执行任务
     */
    public function handle(): void
    {
        // payload 反序列化注入容器单例：handle() 内的 LogBuffer / Sdk 调用都能读到同一 correlation_id
        app()->instance('correlation_id', $this->correlationId);

        $failedException = null;

        try {
            // 整体包事务：lockForUpdate 行锁必须在事务中才真正持有到 COMMIT
            // action 内部可能抛 ApiResponseException（success/error 均是）——必须被 try/catch 兜住，
            // 避免冒出闭包触发 Laravel 自动 rollback，导致 task 状态无法落库
            DB::transaction(function () use (&$failedException) {
                $task = TaskModel::where('id', $this->data['id'] ?? 0)
                    ->where('status', 'executing')
                    ->where('started_at', '<=', now())
                    ->lockForUpdate()
                    ->first();

                if (! $task) {
                    return;
                }

                $action = $task->action;
                $data = [];

                try {
                    if (in_array($action, ['cancel_acme', 'commit_acme', 'sync_acme'], true)) {
                        $method = str_replace('_acme', '', $action); // cancel / commit / sync
                        $acmeAction = new AcmeAction;
                        if (! method_exists($acmeAction, $method)) {
                            throw new \RuntimeException("AcmeAction::$method 方法不存在（请确认 queue worker 已重启加载新代码）");
                        }
                        $acmeAction->$method($task->order_id);
                    } else {
                        $orderAction = new Action;
                        if (! method_exists($orderAction, $action)) {
                            throw new \RuntimeException("Action::$action 方法不存在（请确认 queue worker 已重启加载新代码）");
                        }
                        $orderAction->$action($task->order_id);
                    }
                } catch (ApiResponseException $e) {
                    $response = $e->getApiResponse();
                    $data['result'] = $response;
                    $data['status'] = $response['code'] === 1 ? 'successful' : 'failed';
                } catch (Throwable $e) {
                    $data['result'] = [
                        'code' => 0,
                        'msg' => $e->getMessage(),
                        'data' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'error_code' => $e->getCode(),
                            'previous' => $e->getPrevious()?->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ];
                    $data['status'] = 'failed';
                    $failedException = $e;
                }

                $data['attempts'] = ($task['attempts'] ?? 0) + 1;
                $data['weight'] = 0;
                $data['last_execute_at'] = now();
                $task->update($data);
            });

            // $this->fail() 是 Queue framework 机制，不属于我们的事务范畴
            if ($failedException) {
                $this->fail($failedException);
            }
        } finally {
            // 显式 flush：worker 长驻进程不会在请求结束自动 flush，必须 finally 兜底
            LogBuffer::flush();
        }
    }

    /**
     * 任务失败
     *
     * @throws Throwable
     */
    public function failed(Throwable $e): void
    {
        $task = TaskModel::where('id', $this->data['id'] ?? 0)->first();
        if (! $task) {
            return;
        }

        $adminEmail = get_system_setting('site', 'adminEmail');
        $admin = null;
        if ($adminEmail) {
            $admin = Admin::where('email', $adminEmail)->first();
        }
        $admin ??= Admin::first();

        if (! $admin?->email) {
            return;
        }

        $targetEmail = $adminEmail ?: $admin->email;

        $intent = new NotificationIntent(
            'task_failed',
            'admin',
            $admin->id,
            [
                'task_id' => $task->id,
                'error_message' => $e->getMessage(),
                'admin_email' => $targetEmail,
            ]
        );

        app(NotificationCenter::class)->dispatch($intent);
    }
}
