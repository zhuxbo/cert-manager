<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\Acme;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Task as TaskModel;
use App\Models\Transaction;
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
     * 最大尝试次数 = 5。Laravel 中 job 级 $tries 覆盖生产 worker `--tries 3`
     * （已由 CreateBackupJob=5 + UpgradeFreezeReleaseAttemptsTest 实证生效，勿误以为被 worker 封回 3）。
     * 取 5 的双重理由：
     *  ① 抬升升级 freeze 容忍度：SkipWhenUpgradeFrozen 每次 release(60) 使 attempts+1，tries=3 时
     *     约 3 次 pop（~3min）即被 MaxAttemptsExceeded 杀在 handle 前；tries=5 把误杀阈值抬到 ~5min（缓解件，
     *     根治 freeze 停 worker 属 P0-2）。
     *  ② 并发/mutex 自愈与 freeze release 共享同一 attempts 预算：handle() 内 `attempts() < $tries`
     *     判断并发错误/MutationBusy 是否还能 release 自愈（预算 3→5，自愈次数 2→4），per-retry 行为不变。
     * 有意不加 maxExceptions：TaskJob 自管 release/throw —— 业务失败经 $this->fail() 即终态、从不冒泡重试；
     * 唯一冒泡是预算耗尽的并发/mutex 异常，本就经 tries 立即终态。maxExceptions 对其冗余，且会引入第二套
     * cache 计数模糊自管模型（与 CreateBackupJob 的 tries=5+maxExceptions=1「让业务异常上抛的幂等 job
     * fail-fast」语义不同）。
     */
    public int $tries = 5;

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
                    // C1：取消类 action（cancel/cancel_acme）业务失败必须触发 fail()——否则退款永不发生、
                    // 订单永久卡 cancelling，无重试无告警（只能靠用户投诉）。commit 业务失败由 reconcile
                    // 兜底告警、sync 为 best-effort 不告警，故仅限退款类，不扩散到全部业务失败（防告警风暴）。
                    // 守卫 status==='failed'：成功路径（code=1→'successful'）绝不触发 fail()，不回归「取消静默成功」。
                    // Imp-1 幂等豁免：并发/历史遗留的 cancel task，或孤儿 cancel_acme 任务被唤醒时，
                    // 会撞业务行已终态而 code=0 拒绝（「订单已取消」/
                    // 「订单状态不是取消中」）——退款已发生，属幂等 no-op，不是真·CA 失败，绝不再假告警。
                    // 仅当锁内实测业务行仍非终态（真失败：退款未发生、卡 cancelling）才 fail()。
                    if ($data['status'] === 'failed'
                        && in_array($action, ['cancel', 'cancel_acme'], true)
                        && ! $this->cancellationTargetTerminal($action, (int) $task->order_id)) {
                        $failedException = $e;
                    }
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
     * 取消类任务失败时判定业务行是否已终态（退款已发生的幂等 no-op vs 真·CA 失败）。
     *
     * Imp-1：并发/历史遗留的 cancel task 会撞「订单已取消」，孤儿延时 cancel_acme 会撞
     * 「订单状态不是取消中」——均为 code=0 幂等拒绝，退款已发生，非真失败；
     * 仅在真失败（退款应发生却未发生 / 仍卡 cancelling）时才应告警。
     *
     * order 侧判据（② 收窄，非无差别按状态豁免）：
     *  - revoked / renewed / reissued：终态且无「退款应发生却未发生」的合法性缺口 → 幂等豁免；
     *  - failed **已剔除豁免集**：failed 全系统无任何退款路径，「failed 但退款已发生」不存在合法形态，
     *    force sync 把上游 failed 写过 cancelling 后 cancel task 撞「订单状态不是取消中」读到 failed
     *    是真·CA 失败，必须告警。**注意与 sync 终态守卫的差异**：那里 failed 属【防复活】集
     *    （不让上游旧 active 覆盖终态），语义不同于此处的【退款幂等】判定，两处集合不可混用；
     *  - cancelled：辅以 cancel 流水存在性判定（③ 揭示 sync 可直写 cancelled 而未退款）。
     * acme 侧不含 failed、无此洞，保持原样（② 仅收口 order 侧）。
     *
     * 必须在 handle() 事务内调用（TaskJob 已持 task 行锁）：锁读当前行状态、Transaction 查询同事务快照，
     * 与 cancelLocked 读的同一行一致，锁序 task→order/acme 不反序。
     */
    private function cancellationTargetTerminal(string $action, int $targetId): bool
    {
        if ($action === 'cancel_acme') {
            // 锁 acme 行读最新 status（与 AcmeAction::cancelLocked 的 ->lock() 读同一行）
            $status = Acme::where('id', $targetId)->lock()->value('status');

            return in_array($status, [
                Acme::STATUS_CANCELLED,
                Acme::STATUS_REVOKED,
                Acme::STATUS_EXPIRED,
            ], true);
        }

        // action === 'cancel'：镜像 Order::cancelLocked —— ->lock() 锁 order 行、经 latestCert 读证书态
        $order = Order::with('latestCert')->lock()->find($targetId);
        $cert = $order?->latestCert;
        $status = $cert?->status;

        // 被后继接替（renewed/reissued）/ 吊销（revoked）：终态且无未退款缺口 → 幂等豁免
        if (in_array($status, ['revoked', 'renewed', 'reissued'], true)) {
            return true;
        }

        // cancelled：已退款（有 cancel 流水）或订单应退金额为 0（0 元订单，本就不建流水）
        // → 合法幂等豁免；应退金额>0 却无 cancel 流水 = 退款未发生的真失败 → 不豁免、告警。
        // 已提交上游的 new/renew/reissue 均按 order.amount 退款；pending reissue 的增量退款不会留下 cancel task。
        if ($status === 'cancelled') {
            $hasCancelRefund = Transaction::where('type', 'cancel')
                ->where('transaction_id', $targetId)
                ->exists();
            if ($hasCancelRefund) {
                return true;
            }

            return bccomp((string) $order->amount, '0', 2) <= 0;
        }

        return false;
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
                'result' => ['code' => 0, 'msg' => $this->resolveFailureMessage($e)],
            ]);
        }

        // admin 目标解析单一源（Admin::resolveAlertTarget，原 4 份内联之一）。
        ['admin' => $admin, 'email' => $targetEmail] = Admin::resolveAlertTarget();

        if (! $admin || ! $targetEmail) {
            return;
        }

        $intent = new NotificationIntent(
            'task_failed',
            'admin',
            $admin->id,
            [
                'task_id' => $task->id,
                'error_message' => $this->resolveFailureMessage($e),
                'admin_email' => $targetEmail,
            ]
        );

        app(NotificationCenter::class)->dispatch($intent);
    }

    /**
     * 解析失败消息用于告警/落库。
     *
     * ApiResponseException::getMessage() 恒空（构造不向父传 message，可读消息只在 getApiResponse()['msg']），
     * 直接用 getMessage() 会得到空串（反模式 16）。取法逐字对齐 SubmitDocumentJob::failed。
     */
    private function resolveFailureMessage(Throwable $e): string
    {
        $message = $e instanceof ApiResponseException
            ? (string) ($e->getApiResponse()['msg'] ?? '')
            : $e->getMessage();

        return $message !== '' ? $message : $e::class;
    }
}
