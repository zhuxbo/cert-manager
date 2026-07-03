<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
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
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TaskJob implements ShouldQueue
{
    use DetectsConcurrencyErrors, Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    private const MUTATION_BUSY_RELEASE_MIN_SECONDS = 50;

    private const MUTATION_BUSY_RELEASE_MAX_SECONDS = 70;

    /**
     * 最大尝试次数：与生产 worker `--tries 3` 一致（重试次数不变），显式声明以便 handle()
     * 内用 `$this->attempts() < $this->tries` 判断并发错误是否还能自愈重试。
     */
    public int $tries = 3;

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
                    // 并发错误（死锁 1213 / 锁等待超时 1205 / 序列化失败）：MySQL 已回滚整个事务，
                    // 连接已不在事务中。绝不能继续 $task->update() 或让闭包正常返回触发外层 commit
                    // —— 否则抛 PDOException "There is no active transaction" 且 task 状态错乱。
                    // 抛出 → 逸出闭包触发外层 DB::transaction 回滚 → 由 handle() 外层 catch 统一处理：
                    // 未达 tries 上限静默 release 错峰自愈、达上限才冒出由 failed() 兜底标 failed。
                    // 订单级互斥忙（MutationBusyException，方案 C）与并发错误同等对待：同样抛出 →
                    // 外层 catch 未达上限 release 错峰、达上限冒泡兜底（异步抢不到互斥锁不是业务失败、绝不标 failed）
                    if ($e instanceof DeadlockException
                        || $e instanceof MutationBusyException
                        || $this->causedByConcurrencyError($e)) {
                        throw $e;
                    }
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
        } catch (Throwable $e) {
            // 互斥锁忙不是数据库死锁：另一个请求/任务正在合法持有 order/acme 级业务锁并调上游。
            // 等待窗口必须覆盖上游调用正常耗时（Order 45s / ACME 30s / 锁 TTL 60s），否则 3 次短重试会在锁释放前耗尽并误标 failed。
            if ($e instanceof MutationBusyException && $this->attempts() < $this->tries) {
                $this->release(random_int(self::MUTATION_BUSY_RELEASE_MIN_SECONDS, self::MUTATION_BUSY_RELEASE_MAX_SECONDS));

                return;
            }

            // 数据库并发错误（死锁 1213 / 锁等待 1205 / 序列化失败 40001）：MySQL 已回滚整个事务、连接已不在事务中。
            // 未达 tries 上限时由本 Job 自己短 release 错峰重试 —— 关键：不抛出，worker 不进异常上报路径，
            // 避免每次重试都 report() 把「会自愈的偶发死锁」刷进 error_logs + laravel.log（运维噪音）。
            // 达上限才冒出：worker report 一次（最终失败记录）+ failJob → failed() 兜底标 task failed。
            // release 不碰 task（保持 executing 等下次拾取），与 failed() 兜底标记构成完整闭环。
            if (($e instanceof DeadlockException
                || $this->causedByConcurrencyError($e))
                && $this->attempts() < $this->tries) {
                $this->release(random_int(3, 8)); // 错峰，降低重试又撞同一二级索引间隙的概率

                return;
            }

            throw $e;
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

        // 兜底落库失败状态：handle() 对并发错误改为抛出（不在死事务内 update），
        // 重试耗尽进入本钩子时 task 仍是 executing，不标记会被 checkRepeat 当"处理中"永久阻塞后续。
        // 本钩子在 job 彻底失败后调用，无外层事务，autocommit 下 update 安全；
        // 普通业务异常路径已在 handle() 内标过 failed（status != executing），守卫跳过避免重复。
        if ($task->status === 'executing') {
            $task->update([
                'status' => 'failed',
                'weight' => 0,
                'last_execute_at' => now(),
                'attempts' => ($task->attempts ?? 0) + 1,
                'result' => ['code' => 0, 'msg' => $e->getMessage()],
            ]);
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
