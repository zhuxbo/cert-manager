<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ApiResponseException;
use App\Models\Admin;
use App\Models\Task as TaskModel;
use App\Services\Acme\Action as AcmeAction;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class TaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * 执行任务
     */
    public function handle(): void
    {
        $task = TaskModel::where('id', $this->data['id'] ?? 0)
            ->where('status', 'executing')
            ->where('started_at', '<=', now())
            ->lockForUpdate()
            ->first();

        $failedException = null;
        if ($task) {
            $action = $task->action;

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
        }

        if ($failedException) {
            $this->fail($failedException);
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
            ],
            ['mail']
        );

        app(NotificationCenter::class)->dispatch($intent);
    }
}
