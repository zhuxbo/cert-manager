<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Exceptions\ApiResponseException;
use App\Jobs\TaskJob;
use App\Models\Acme;
use App\Models\Product;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Acme\Api\Api;
use App\Services\Order\Utils\OrderUtil;
use App\Support\MutexLock;
use App\Traits\ApiResponse;
use App\Traits\RunsTaskMutationTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Action
{
    use ApiResponse;
    use MutexLock;
    use RunsTaskMutationTransaction;

    // directory_url 缓存 TTL（天）：directory 端点极稳定，长 TTL 既压低回源请求（每 CA 至多每 TTL 一次），
    // 又避免 forever 在 CA 极偶发换端点时永久驻留错值——过期后 show 读路径自动回源刷新
    private const DIRECTORY_URL_CACHE_TTL_DAYS = 30;

    /**
     * 创建 ACME 订单（unpaid 状态）
     */
    public function new(array $params): void
    {
        $acme = $this->createOrder($params);
        $this->success(['order_id' => $acme->id]);
    }

    /**
     * 支付订单 — Admin/User 入口默认"先支付，再提交"两步独立事务
     *
     * autoCommit=true（默认）：先 payOrder 独立完成扣费 → pending；
     *   再独立事务调用 commitOrder 提交上游。提交失败 **不撤回扣费**，
     *   订单保留 pending 状态，可由 commit 接口重试。
     * autoCommit=false（batchPay 内部复用）：仅扣费置 pending，提交由调用方入队处理。
     *
     * 与 newAndCommit（API 入口）的"扣费提交一体事务"语义有意区分。
     */
    public function pay(int $acmeId, bool $autoCommit = true): void
    {
        // order 级互斥（方案 C）：ACME pay 走 private commitOrder 下单（不经公共 commit），
        // 故必须自包 acme_mutate_{id}（与 Order pay 走公共 commit 不同），否则 pay×commit 并发下单无保护
        $this->withMutex("acme_mutate_$acmeId", fn () => $this->payLocked($acmeId, $autoCommit));
    }

    /**
     * 支付（锁内实现）—— 必须经 pay() 持有 acme_mutate_{id} 互斥锁后调用
     */
    private function payLocked(int $acmeId, bool $autoCommit = true): void
    {
        $acme = Acme::findOrFail($acmeId);
        $this->payOrder($acme);

        if (! $autoCommit) {
            $this->success();
        }

        // 提交单独事务执行；失败抛出异常时扣费已提交不会回滚
        $acme = DB::transaction(function () use ($acmeId) {
            $locked = Acme::where('id', $acmeId)->lock()->firstOrFail();

            return $this->commitOrder($locked);
        });

        $this->success([
            'order_id' => $acme->id,
            'eab_kid' => $acme->eab_kid,
            'eab_hmac' => $acme->eab_hmac,
            'directory_url' => $this->syncDirectoryUrl($acme),
        ]);
    }

    /**
     * 批量支付订单
     *
     * 逐条独立事务执行，单条失败收集到 errors，不影响其他。
     * 仅处理 unpaid 状态订单，非 unpaid 静默过滤。
     */
    public function batchPay(array $acmeIds): void
    {
        $maxUpstream = config('batch.max_upstream');
        count($acmeIds) > $maxUpstream && $this->error("订单数量不能超过{$maxUpstream}");

        $payableIds = Acme::whereIn('id', $acmeIds)
            ->where('status', Acme::STATUS_UNPAID)
            ->pluck('id')
            ->all();

        if (empty($payableIds)) {
            $this->error('没有可以支付的订单');
        }

        $successIds = [];
        $errors = [];

        foreach ($payableIds as $id) {
            try {
                (new self)->pay($id, false);  // 禁用单体 autoCommit，由 batchPay 统一批量创建
            } catch (ApiResponseException $e) {
                $res = $e->getApiResponse();
                if (($res['code'] ?? 0) === 1) {
                    $successIds[] = $id;
                } else {
                    $errors[] = ['id' => $id, 'msg' => $res['msg'] ?? '支付失败'];
                }
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'msg' => $e->getMessage()];
            }
        }

        // 支付成功的订单自动入队 commit_acme（对齐 Order batchPay 默认 commit=true 语义）
        // 跳过已有 executing 任务的 id 以防重复
        $commitIds = [];
        if (! empty($successIds)) {
            $existing = Task::whereIn('order_id', $successIds)
                ->where('action', 'commit_acme')
                ->where('status', 'executing')
                ->pluck('order_id')
                ->all();
            $commitIds = array_values(array_diff($successIds, $existing));
            if (! empty($commitIds)) {
                $this->createTasks($commitIds, 'commit_acme');
            }
        }

        $this->success([
            'success_count' => count($successIds),
            'commit_count' => count($commitIds),
            'errors' => $errors,
        ]);
    }

    /**
     * 批量提交订单
     */
    public function batchCommit(array $acmeIds): void
    {
        $ids = Acme::whereIn('id', $acmeIds)
            ->where('status', Acme::STATUS_PENDING)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            $this->error('没有可以提交的订单');
        }

        $this->checkRepeat($ids, 'commit_acme');
        $this->createTasks($ids, 'commit_acme');

        $this->success();
    }

    /**
     * 批量同步订单状态
     *
     * 仅 active/cancelling 可同步（有 api_id）；pending/unpaid 被过滤。
     */
    public function batchSync(array $acmeIds): void
    {
        $ids = Acme::whereIn('id', $acmeIds)
            ->whereIn('status', [Acme::STATUS_ACTIVE, Acme::STATUS_CANCELLING])
            ->whereNotNull('api_id')
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            $this->error('没有可以同步的订单');
        }

        $this->checkRepeat($ids, 'sync_acme');
        $this->createTasks($ids, 'sync_acme');

        $this->success();
    }

    /**
     * 批量取消订单
     *
     * 允许状态：unpaid / pending / active。
     * 实际处理由单体 commitCancel 决定：unpaid 或无 api_id 的 pending 直接退费，
     * 其余创建 cancel_acme Task 延时 123s。逐条独立事务。
     */
    public function batchCommitCancel(array $acmeIds): void
    {
        $maxUpstream = config('batch.max_upstream');
        count($acmeIds) > $maxUpstream && $this->error("订单数量不能超过{$maxUpstream}");

        $ids = Acme::whereIn('id', $acmeIds)
            ->whereIn('status', [Acme::STATUS_UNPAID, Acme::STATUS_PENDING, Acme::STATUS_ACTIVE])
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            $this->error('没有可以取消的订单');
        }

        $successCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                (new self)->commitCancel($id);
            } catch (ApiResponseException $e) {
                $res = $e->getApiResponse();
                if (($res['code'] ?? 0) === 1) {
                    $successCount++;
                } else {
                    $errors[] = ['id' => $id, 'msg' => $res['msg'] ?? '取消失败'];
                }
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'msg' => $e->getMessage()];
            }
        }

        $this->success(['success_count' => $successCount, 'errors' => $errors]);
    }

    /**
     * 批量撤回取消
     *
     * 仅 cancelling 状态可撤回；逐条调单体 revokeCancel（已含悲观锁 + Task 清理）。
     */
    public function batchRevokeCancel(array $acmeIds): void
    {
        $maxUpstream = config('batch.max_upstream');
        count($acmeIds) > $maxUpstream && $this->error("订单数量不能超过{$maxUpstream}");

        $ids = Acme::whereIn('id', $acmeIds)
            ->where('status', Acme::STATUS_CANCELLING)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            $this->error('没有可以撤回取消的订单');
        }

        $successCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                (new self)->revokeCancel($id);
            } catch (ApiResponseException $e) {
                $res = $e->getApiResponse();
                if (($res['code'] ?? 0) === 1) {
                    $successCount++;
                } else {
                    $errors[] = ['id' => $id, 'msg' => $res['msg'] ?? '撤回失败'];
                }
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'msg' => $e->getMessage()];
            }
        }

        $this->success(['success_count' => $successCount, 'errors' => $errors]);
    }

    /**
     * 提交订单到上游系统 — 成功后状态 → active
     *
     * 并发安全：事务内持 acme 行级锁，包含上游 API 调用。与 commitCancel 串行化，
     * 避免"提交到上游 + 本地状态被 commitCancel 改为 cancelled 后又被 commit 覆盖回 active"
     * 导致的"已退款但订单仍激活"或"上游订单存在但本地 cancelled"的资金/状态错乱。
     */
    public function commit(int $acmeId): void
    {
        // order 级互斥（方案 C）：与 pay/cancel/cancelNow 共用 acme_mutate_{id}，commit/cancel 串行防 1205
        $this->withMutex("acme_mutate_$acmeId", fn () => $this->commitLocked($acmeId));
    }

    /**
     * 提交（锁内实现）—— 必须经 commit() 持有 acme_mutate_{id} 互斥锁后调用
     */
    private function commitLocked(int $acmeId): void
    {
        $acme = DB::transaction(function () use ($acmeId) {
            $locked = Acme::where('id', $acmeId)->lock()->firstOrFail();

            return $this->commitOrder($locked);
        });

        $this->success([
            'order_id' => $acme->id,
            'eab_kid' => $acme->eab_kid,
            'eab_hmac' => $acme->eab_hmac,
            'directory_url' => $this->syncDirectoryUrl($acme),
        ]);
    }

    /**
     * 一步到位：创建 + 支付 + 提交，失败时回滚全部记录
     */
    public function newAndCommit(array $params): void
    {
        $acme = DB::transaction(function () use ($params) {
            $acme = $this->createOrder($params);
            $acme = $this->payOrder($acme);

            return $this->commitOrder($acme);
        });

        $this->success([
            'order_id' => $acme->id,
            'eab_kid' => $acme->eab_kid,
            'eab_hmac' => $acme->eab_hmac,
            'status' => $acme->status,
            'directory_url' => $this->syncDirectoryUrl($acme),
        ]);
    }

    /**
     * 提交取消 — 标记 cancelling + 创建延时任务
     *
     * 并发安全：按 "task → acme" 的统一锁顺序拿锁（与 TaskJob/sync/revokeCancel 一致），
     * 避免与 sync 的 cancel_acme 间隙锁形成反序死锁环；纯本地变更（上游调用在延时任务内），
     * 由 runTaskMutationTransaction 对 1213/1205 静默重试。
     */
    public function commitCancel(int $acmeId): void
    {
        $this->runTaskMutationTransaction(function () use ($acmeId) {
            // 锁顺序 1：先锁 task（Task::lockForMutation 强制复合索引，与 Order 侧统一）
            Task::lockForMutation($acmeId, ['cancel_acme'])->get();

            // 锁顺序 2：再锁 acme
            $acme = Acme::where('id', $acmeId)->lock()->firstOrFail();

            if (! in_array($acme->status, [Acme::STATUS_UNPAID, Acme::STATUS_ACTIVE, Acme::STATUS_PENDING])) {
                $this->error('当前状态不允许取消');
            }

            // 未支付订单，直接标记取消（无扣费记录，无需退费）
            if ($acme->status === Acme::STATUS_UNPAID) {
                $acme->update([
                    'status' => Acme::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

                return;
            }

            // 未提交上游的 pending 订单，直接退费取消
            if ($acme->status === Acme::STATUS_PENDING && ! $acme->api_id) {
                $this->refund($acme);
                $acme->update([
                    'status' => Acme::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

                return;
            }

            $acme->update(['status' => Acme::STATUS_CANCELLING]);

            // 检查是否已存在相同的执行中任务，避免重复创建
            $existingTask = Task::where('order_id', $acme->id)
                ->where('action', 'cancel_acme')
                ->where('status', 'executing')
                ->first();

            if (! $existingTask) {
                $task = Task::create([
                    'order_id' => $acme->id,
                    'action' => 'cancel_acme',
                    'started_at' => now()->addSeconds(120),
                    'status' => 'executing',
                    'source' => getControllerCategory(),
                ]);

                // afterCommit 防止 worker 在外层事务提交前消费 job 导致 task 查无记录静默丢失
                TaskJob::dispatch(['id' => $task->id])
                    ->afterCommit()
                    ->delay(now()->addSeconds(123))
                    ->onQueue(config('queue.names.tasks'));
            }
        });

        $this->success();
    }

    /**
     * 立即取消 — 不走延时任务，同步调上游并退费
     *
     * 下游 API（/api/v2/acme/cancel）场景使用；Web 入口仍走 commitCancel 延时流程，保留撤回窗口。
     * 并发安全：整个流程（状态校验 + 上游调用 + 退费 + 状态更新）在同一事务内持有 acme 行级锁，
     * 避免与 revokeCancel 产生"退费成功 + 订单被吊销"的双重损害。
     */
    public function cancelNow(int $acmeId): void
    {
        // order 级互斥（方案 C）：下游同步取消，与 commit/pay 共用 acme_mutate_{id} 串行
        $this->withMutex("acme_mutate_$acmeId", fn () => $this->cancelNowLocked($acmeId));
    }

    /**
     * 立即取消（锁内实现）—— 必须经 cancelNow() 持有 acme_mutate_{id} 互斥锁后调用
     */
    private function cancelNowLocked(int $acmeId): void
    {
        DB::transaction(function () use ($acmeId) {
            $acme = Acme::where('id', $acmeId)->lock()->firstOrFail();

            if (! in_array($acme->status, [Acme::STATUS_ACTIVE, Acme::STATUS_PENDING])) {
                $this->error('当前状态不允许取消');
            }

            $this->performCancel($acme);
        });

        $this->success();
    }

    /**
     * 撤回取消 — 清理延时任务，状态回滚至 active
     *
     * 仅在 acme 状态仍为 cancelling 时有效。
     * 并发安全：按 "task → acme" 的统一锁顺序拿锁（与 TaskJob::handle 一致），避免死锁。
     * 若 TaskJob 正在 cancel 内，此处 task lockForUpdate 会阻塞至 TaskJob 提交；
     * 拿到 task 锁后再锁 acme，此时 status 已非 cancelling，校验报错退出。
     */
    public function revokeCancel(int $acmeId): void
    {
        $this->runTaskMutationTransaction(function () use ($acmeId) {
            // 锁顺序 1：先锁 task（与 TaskJob 一致，避免 task↔acme 循环等待死锁）
            // Task::lockForMutation 强制复合索引 tasks_order_action_status_index（与 Order 侧统一）
            Task::lockForMutation($acmeId, ['cancel_acme'])->get();

            // 锁顺序 2：再锁 acme
            $acme = Acme::where('id', $acmeId)->lock()->firstOrFail();

            if ($acme->status !== Acme::STATUS_CANCELLING) {
                $this->error('订单不在取消中状态');
            }

            Task::where('order_id', $acme->id)
                ->where('action', 'cancel_acme')
                ->whereIn('status', ['executing', 'stopped'])
                ->delete();

            $acme->update(['status' => Acme::STATUS_ACTIVE]);
        });

        $this->success();
    }

    /**
     * 执行取消 — 延时任务调用，调 Api->cancel()，退费处理
     *
     * 并发安全：整个流程（状态校验 + 上游调用 + 退费 + 状态更新）在同一事务内持有 acme 行级锁，
     * 锁粒度仅为 acme 单行，不影响产品/用户；即使上游调用耗时数秒也可接受，避免 revokeCancel 穿插
     * 导致的资金与状态不一致。
     */
    public function cancel(int $acmeId): void
    {
        // order 级互斥（方案 C）：延时任务取消，与 commit/pay 共用 acme_mutate_{id} 串行
        $this->withMutex("acme_mutate_$acmeId", fn () => $this->cancelLocked($acmeId));
    }

    /**
     * 执行取消（锁内实现）—— 必须经 cancel() 持有 acme_mutate_{id} 互斥锁后调用
     */
    private function cancelLocked(int $acmeId): void
    {
        DB::transaction(function () use ($acmeId) {
            $acme = Acme::where('id', $acmeId)->lock()->firstOrFail();

            if ($acme->status !== Acme::STATUS_CANCELLING) {
                $this->error('订单状态不是取消中');
            }

            $this->performCancel($acme);
        });

        $this->success();
    }

    /**
     * 取消公共逻辑（内部方法）— 必须在外层事务且 acme 行已锁的前提下调用
     *
     * 根据 api_id 决定是否调上游；退费与最终状态更新在锁内完成。
     * 上游返回 status=revoked 则本地落 revoked，否则（含无 api_id 直接退费场景）落 cancelled。
     */
    private function performCancel(Acme $acme): void
    {
        $targetStatus = Acme::STATUS_CANCELLED;

        if ($acme->api_id) {
            try {
                $result = (new Api)->cancel($acme->id);
            } catch (ApiResponseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }

            if (($result['data']['status'] ?? '') === 'revoked') {
                $targetStatus = Acme::STATUS_REVOKED;
            }
        }

        $this->refund($acme);
        $acme->update([
            'status' => $targetStatus,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * 同步订单状态 — 从上游系统拉取最新状态
     *
     * @param  bool  $force  true 时静默返回（供 get 内部调用），false 时返回 success 响应（供 Admin 调用）
     */
    public function sync(int $acmeId, bool $force = false): void
    {
        $acme = Acme::find($acmeId);

        if (! $acme || ! $acme->api_id) {
            if ($force) {
                return;
            }
            $this->error($acme ? '订单尚未提交到上游' : '订单不存在');
        }

        // 原子占位：10 秒内不重复向上请求（Cache::add SETNX 防并发同一 acme 击穿重复调上游）。
        // 放在 find/api_id 校验之后、上游调用之前：acme 不存在/未提交始终走 error，不会因占位变 success
        $cacheKey = "acme_sync_$acmeId";
        if (! Cache::add($cacheKey, time(), 10)) {
            if ($force) {
                return;
            }
            $this->success();
        }

        // 慢 IO（上游 Guzzle）放在行锁之外，避免长时间持锁。
        // 占位回滚：上游调用/写回失败时必须 Cache::forget 占位，否则 10s 内重试命中占位直接返回 success，
        // 把"实际未同步"的失败伪装成成功。成功路径不回滚（占位正是为了 10s 内防重复上游请求）。
        try {
            $result = (new Api)->get($acme->id);
            $data = $result['data'] ?? [];

            // 锁内重取 + 终态守卫 + 写回：锁序 task→acme（sync_acme 经 TaskJob 已持 task 锁）。
            // 杀手场景：并发 cancel 在锁内退款并置 cancelled，本 sync 若用上游滞后的 active 覆盖会让已退款订阅复活。
            $directoryUrl = (string) ($data['directory_url'] ?? '');
            // T7：上游响应终态判据（事务外，基于 HTTP 响应快照，非本地行状态）——决定是否先锁 cancel_acme，
            // 避免"先锁 acme 判 cancelling 再锁 task"的 acme→task 反序死锁。高频 get 常态 active 零 task 锁开销。
            $upstreamTerminal = in_array(
                $data['status'] ?? null,
                [Acme::STATUS_CANCELLED, Acme::STATUS_REVOKED, Acme::STATUS_EXPIRED],
                true
            );
            $ca = $this->runTaskMutationTransaction(function () use ($acmeId, $data, $upstreamTerminal) {
                if ($upstreamTerminal) {
                    // 锁序 1：先锁 cancel_acme task（镜像 revokeCancel），仅上游终态才触发（与执行条件对齐、无冗余锁）
                    Task::lockForMutation($acmeId, ['cancel_acme'])->get();
                }
                // 锁序 2：锁 acme 行
                $acme = Acme::where('id', $acmeId)->lock()->first();
                if (! $acme) {
                    return '';
                }

                // T7：本地 cancelling + 上游终态 → 完成在途取消（退款 + 清孤儿 cancel_acme 任务）。
                // 判据用锁内重取的 acme 行自身（读=写同一行）；ACME 无 latestCert 切换，天然满足红线。
                // 缺口修复：D1 只堵 (cancelling, upstream=active) 半格，(cancelling, upstream∈终态) 三格原会写终态
                // 但不退款、延时 cancel_acme 到点撞"状态不是取消中"断死 → under-refund。此处补退款闭合。
                if ($acme->status === Acme::STATUS_CANCELLING && $upstreamTerminal) {
                    // 预检 acme_cancel 已存在（边缘态：已退款但状态回滚卡 cancelling）→ 跳过退款只补状态，
                    // 避免防重 throw 逸出致 sync 假失败；预检竞态残留由一对一防重 + 金额抵消双层物理阻断兜底。
                    $alreadyRefunded = Transaction::where('type', Transaction::TYPE_ACME_CANCEL)
                        ->where('transaction_id', $acme->id)
                        ->exists();
                    if (! $alreadyRefunded) {
                        // 与传统订单一致：退款异常直接回滚并沿任务失败链暴露；
                        // 每日 finance:audit 负责账本对账，不发送 ACME 专属即时告警。
                        $this->refund($acme);
                    }
                    // 清孤儿 cancel_acme 任务（lockForMutation 已覆盖 executing/stopped 二态，锁与删同界）
                    Task::where('order_id', $acme->id)
                        ->where('action', 'cancel_acme')
                        ->whereIn('status', ['executing', 'stopped'])
                        ->delete();
                    $acme->update([
                        'status' => $data['status'],
                        'cancelled_at' => $acme->cancelled_at ?? now(),
                    ]);

                    // T7 分支为终态收尾，有意不合并 vendor_id/period 等非状态字段（终态元数据以取消时刻为准）
                    return (string) ($acme->product->ca ?? '');
                }

                $updateData = [];
                $syncableStatuses = [Acme::STATUS_ACTIVE, Acme::STATUS_REVOKED, Acme::STATUS_EXPIRED, Acme::STATUS_CANCELLED];
                // 终态守卫：本地已是终态（cancelled/revoked/expired）时拒绝上游 status 覆盖，防滞后 active 复活已退款订阅
                $localTerminal = in_array($acme->status, [Acme::STATUS_CANCELLED, Acme::STATUS_REVOKED, Acme::STATUS_EXPIRED], true);
                // cancelling 守卫：本地取消中时仅挡上游滞后 active 回写（上游终态 cancelled/revoked/expired 已由上方 T7 分支处理），
                // 否则 active 覆盖后延时 cancel_acme 任务因状态非 cancelling 抛错、用户取消意图静默丢弃（P1-4）
                $cancellingRevivedByActive = $acme->status === Acme::STATUS_CANCELLING
                    && ($data['status'] ?? null) === Acme::STATUS_ACTIVE;
                if (! $localTerminal && ! $cancellingRevivedByActive && isset($data['status']) && in_array($data['status'], $syncableStatuses, true)) {
                    $updateData['status'] = $data['status'];
                    // 上游已取消/吊销且本地尚未记录取消时间 → 用当前时间补记（正式取消时间）
                    if (in_array($data['status'], [Acme::STATUS_CANCELLED, Acme::STATUS_REVOKED], true) && ! $acme->cancelled_at) {
                        $updateData['cancelled_at'] = now();
                    }
                }
                // 非状态字段不受终态守卫限制，仍按上游合并
                if (isset($data['vendor_id'])) {
                    $updateData['vendor_id'] = $data['vendor_id'];
                }
                // 历史订单 contact_email 可能为空，从上游 sync 回填；已有值也以上游为准保持一致
                if (isset($data['contact_email']) && $data['contact_email'] !== '') {
                    $updateData['contact_email'] = $data['contact_email'];
                }
                // ACME 本地仅记录占位周期（commit 时的 now），上游是权威数据源，sync 时以上游为准覆盖
                if (isset($data['period_from'])) {
                    $updateData['period_from'] = $data['period_from'];
                }
                if (isset($data['period_till'])) {
                    $updateData['period_till'] = $data['period_till'];
                }
                if (! empty($updateData)) {
                    $acme->update($updateData);
                }

                return (string) ($acme->product->ca ?? '');
            }); // runTaskMutationTransaction 统一 attempts=3：与 Order sync 对齐；上游 get 在事务外，重试只重跑锁+写回，不重复调上游
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            throw $e;
        }

        if ($directoryUrl !== '') {
            $this->cacheDirectoryUrl($ca, $directoryUrl);
        }

        if (! $force) {
            $this->success();
        }
    }

    /**
     * 备注
     */
    public function remark(int $acmeId, string $remark, string $field = 'remark'): void
    {
        $acme = Acme::findOrFail($acmeId);
        $acme->update([$field => $remark]);
        $this->success();
    }

    /**
     * 创建订单（内部方法）
     *
     * 额度按产品的 standard_max / wildcard_max 自动推断：ACME 当前只保留单域名 / 单通配符两种
     * period 未传则用 product.periods[0]（对外 API 与 gateway 对齐，不接 period 入参）
     * refer_id 支持外部透传（端到端幂等键）；未传则 manager 内部生成
     */
    private function createOrder(array $params): Acme
    {
        $product = Product::where('id', $params['product_id'] ?? 0)
            ->where('product_type', Product::TYPE_ACME)
            ->first();

        if (! $product) {
            $this->error('产品不存在或不支持 ACME');
        }

        if (isset($params['period'])) {
            $period = (int) $params['period'];
            if (! in_array($period, $product->periods)) {
                $this->error('无效的购买时长');
            }
        } else {
            // period 未传时回落产品首个周期；periods 为空表示产品未配置周期，报错而非硬编码 12 绕过校验
            if (empty($product->periods)) {
                $this->error('产品未配置周期');
            }
            $period = (int) $product->periods[0];
        }

        [$standardCount, $wildcardCount] = $this->resolveDomainCounts($product);

        // 计算订单金额；ACME SAN 数量直接使用 acme.purchased_*，无 Cert 模型
        $amount = OrderUtil::getLatestCertAmount(
            [
                'user_id' => $params['user_id'],
                'product_id' => $params['product_id'],
                'period' => $period,
                'purchased_standard_count' => $standardCount,
                'purchased_wildcard_count' => $wildcardCount,
            ],
            ['action' => 'new'],
            $product->toArray()
        );

        return Acme::create([
            'user_id' => $params['user_id'],
            'product_id' => $params['product_id'],
            'brand' => $product->brand,
            'period' => $period,
            'plus' => (int) ($params['plus'] ?? 1) === 0 ? 0 : 1,
            'purchased_standard_count' => $standardCount,
            'purchased_wildcard_count' => $wildcardCount,
            'refer_id' => $params['refer_id'] ?? bin2hex(random_bytes(16)),
            'contact_email' => $params['contact_email'] ?? null,
            'amount' => $amount,
            'status' => Acme::STATUS_UNPAID,
            'channel' => $params['channel'] ?? 'web',
            'remark' => $params['remark'] ?? null,
        ]);
    }

    /**
     * 按产品 standard_max / wildcard_max 推断域名额度
     */
    private function resolveDomainCounts(Product $product): array
    {
        $standardMax = (int) ($product->standard_max ?? 0);
        $wildcardMax = $product->wildcard_max;

        if ($standardMax === 0 && $wildcardMax >= 1) {
            return [0, 1];
        }

        return [1, 0];
    }

    /**
     * 获取 ACME directory URL — 系统 Cache 优先，缺失则同步上游再查询
     *
     * 上游为权威数据源；Manager 以系统 Cache（key `acme_directory_url:{ca}`，按签发 CA 聚合）做长期缓存。
     * commit / sync 用上游返回值刷新缓存；show 查不到时回源上游 get 拉取并写回。
     * 缓存被清理只是多一次向上游同步，不影响正确性。
     */
    public function syncDirectoryUrl(Acme $acme): ?string
    {
        $ca = $this->normalizeCa($acme->product->ca ?? '');
        if ($ca === '') {
            return null;
        }

        $cached = Cache::get($this->directoryUrlCacheKey($ca));
        if ($cached) {
            return (string) $cached;
        }

        if (! $acme->api_id) {
            return null;
        }

        try {
            // 复用 sync() 的 10s 防抖窗口，避免详情页反复点击触发重复上游请求；
            // 顺带更新 status / period 等上游权威字段
            $this->sync($acme->id, true);
            $cached = Cache::get($this->directoryUrlCacheKey($ca));
            if ($cached) {
                return (string) $cached;
            }
        } catch (\Throwable) {
            // 上游暂不可达不应阻塞详情接口，静默降级为 null
        }

        return null;
    }

    /**
     * 写入/刷新 directory URL 缓存（长 TTL，按 CA 聚合）
     *
     * 用 put + TTL 而非 forever：过期即自动纳入 syncDirectoryUrl 既有「缓存 miss 回源」分支，
     * 无需新增读路径逻辑，即可让 CA 极偶发换端点时错值最终被刷新（详见 TTL 常量注释）。
     */
    private function cacheDirectoryUrl(string $ca, ?string $url): void
    {
        $ca = $this->normalizeCa($ca);
        if ($ca === '' || ! $url) {
            return;
        }

        Cache::put(
            $this->directoryUrlCacheKey($ca),
            $url,
            now()->addDays(self::DIRECTORY_URL_CACHE_TTL_DAYS)
        );
    }

    private function directoryUrlCacheKey(string $ca): string
    {
        return "acme_directory_url:$ca";
    }

    /**
     * CA 标识归一（小写 + trim）— 与 directory_url 缓存 key 口径一致
     *
     * public 供 batchShow 等调用方按 CA 去重时复用同款归一，避免去重 map key 与 service 层缓存 key 不一致
     */
    public function normalizeCa(string $ca): string
    {
        return strtolower(trim($ca));
    }

    /**
     * 支付订单（内部方法）
     *
     * 并发安全：acme 行锁 + user 行锁，同一用户跨订单并发支付时序列化余额校验，
     * 避免两笔不同订单都基于旧余额通过 credit_limit 检查后双双扣款至额度以下。
     */
    private function payOrder(Acme $acme): Acme
    {
        DB::transaction(function () use ($acme) {
            // 锁内重取 acme：串行化同一订单的重复支付
            $locked = Acme::where('id', $acme->id)->lock()->firstOrFail();

            if ($locked->status !== Acme::STATUS_UNPAID) {
                $this->error('订单不是未支付状态');
            }

            // 锁内取 user：序列化同一用户并发的不同订单支付。
            // Transaction::creating 虽也 lockForUpdate user 并扣款，但不再校验 credit_limit，
            // 若此处仅读快照通过校验，并发的另一订单会把余额推至额度以下。
            $user = User::where('id', $locked->user_id)->lockForUpdate()->firstOrFail();

            // 构造交易数据（金额取负数表示扣费）
            $transactionAmount = '-'.$locked->amount;

            // 管理员支付跳过余额检测，允许欠费支付
            $balanceAfter = bcadd((string) $user->balance, $transactionAmount, 2);
            if (bccomp($balanceAfter, (string) $user->credit_limit, 2) === -1) {
                Auth::guard('admin')->check() || $this->error('余额不足');
            }

            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_ACME_ORDER,
                'transaction_id' => $locked->id,
                'amount' => $transactionAmount,
                'standard_count' => $locked->purchased_standard_count,
                'wildcard_count' => $locked->purchased_wildcard_count,
            ]);

            $locked->update(['status' => Acme::STATUS_PENDING]);
        });

        return Acme::findOrFail($acme->id);
    }

    /**
     * 提交订单到 Gateway（内部方法）
     *
     * data 字段集（manager 视角的完整 schema）：
     * product_code / contact_email / period / plus(int 0/1) / refer_id
     * source 是 manager 内部 Api 路由参数，作为 (new Api)->new 的第二个独立参数，不混入 data
     * plus 与传统 Order 一致用 int 0/1（gateway 端 (bool) cast 兼容）
     * period 当前 gateway /api/v2/acme/new validate 暂不接收（由 product.periods[0] 决定）；
     * 但 manager 这一侧视为完整 schema 一部分稳定外发，等 gateway 升级多年期后自然贯通
     */
    private function commitOrder(Acme $acme): Acme
    {
        if ($acme->status !== Acme::STATUS_PENDING) {
            $this->error('订单状态不是待提交');
        }

        $product = $acme->product;

        // 下单时已在所有入口 validate 必填，此处只做防御性断言
        if (! $acme->contact_email) {
            $this->error('ACME 账号邮箱缺失，无法提交订单');
        }

        $data = [
            'contact_email' => $acme->contact_email,
            'product_code' => $product->code,
            'period' => $acme->period,
            'plus' => $acme->plus,
            'refer_id' => $acme->refer_id,
        ];

        try {
            $result = (new Api)->new($data, $product->source);
        } catch (ApiResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }

        $data = $result['data'] ?? [];
        if (empty($data['order_id'])) {
            // 上游权威字段为 data.order_id（非 api_id），缺失即视为响应异常
            $this->error('上游返回缺少 order_id，无法登记 ACME 订单');
        }
        $acme->update([
            'api_id' => $data['order_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            // Certum 正常返回 customer.name，Gateway 透传为 contact_email；缺失则保持本地下单时写入的值
            'contact_email' => $data['contact_email'] ?? $acme->contact_email,
            'eab_kid' => $data['eab_kid'] ?? null,
            'eab_hmac' => $data['eab_hmac'] ?? null,
            // 上游返回订单周期则覆盖本地值，否则用本地计算（now 起算）
            'period_from' => $data['period_from'] ?? now(),
            'period_till' => $data['period_till'] ?? now()->addMonths($acme->period),
            'status' => Acme::STATUS_ACTIVE,
        ]);

        if (! empty($data['directory_url'])) {
            $this->cacheDirectoryUrl((string) $product->ca, (string) $data['directory_url']);
        }

        return $acme->refresh();
    }

    /**
     * 退费处理（内部方法）
     *
     * protected 而非 private：供 T7 sync 退款回滚与并发重试测试用 Mockery partial 注入异常。
     * 无外部调用，封装不受影响。
     */
    protected function refund(Acme $acme): void
    {
        $transaction = OrderUtil::getCancelTransaction(
            $acme->toArray(),
            Transaction::TYPE_ACME_CANCEL
        );
        Transaction::create($transaction);
    }

    /**
     * 检查是否存在 executing 状态的重复任务
     */
    private function checkRepeat(array $acmeIds, string $action): void
    {
        $exists = Task::where('action', $action)
            ->whereIn('order_id', $acmeIds)
            ->where('status', 'executing')
            ->exists();

        if ($exists) {
            $this->error('已存在处理中的任务，请稍后刷新页面');
        }
    }

    /**
     * 为单个 ACME 订单入队 commit_acme 对账任务（T6 ReconcileAcmeCommand 复用）。
     *
     * 复用 createTasks 逐条幂等模式（skip-if-executing + afterCommit + onQueue(tasks)）；不用 checkRepeat
     * （throw 语义不适合扫描循环）。重发同 refer_id 时上游 Case A（已成功建单、行有 EAB）幂等返回 order+EAB
     * → commitOrder 回填 api_id/EAB/active 自愈；vendor_id/period 自愈瞬间为 null/本地近似，由后续 sync 从
     * 上游回填校正（EAB 关键契约即时可达）。Case B（占位遗留、EAB 空）恒返通用可重试 msg，与瞬时并发不可
     * 区分，靠 max_attempts 有界退避 → 超限 SystemAlert 转人工（不做 msg 启发式）。
     */
    public function queueCommit(int $acmeId): void
    {
        $this->createTasks([$acmeId], 'commit_acme');
    }

    /**
     * 批量创建 Task 并 dispatch TaskJob（started_at 可选延时）
     */
    private function createTasks(array $acmeIds, string $action, int $delaySeconds = 0): void
    {
        $startedAt = $delaySeconds > 0 ? now()->addSeconds($delaySeconds) : now();
        $source = getControllerCategory();

        foreach ($acmeIds as $id) {
            // 检查是否已存在相同的执行中任务，避免重复创建（对齐 Order createTask 的逐条幂等）
            $existingTask = Task::where('order_id', $id)
                ->where('action', $action)
                ->where('status', 'executing')
                ->first();

            if ($existingTask) {
                continue;
            }

            $task = Task::create([
                'order_id' => $id,
                'action' => $action,
                'started_at' => $startedAt,
                'status' => 'executing',
                'source' => $source,
            ]);

            // afterCommit 防止 worker 在外层事务提交前消费 job 导致 task 查无记录静默丢失
            $job = TaskJob::dispatch(['id' => $task->id])
                ->afterCommit()
                ->onQueue(config('queue.names.tasks'));

            if ($delaySeconds > 0) {
                // 队列定时比可执行时间（started_at）多 3 秒缓冲，避免 job 在事务提交/行可见前被消费（对齐 Order createTask）
                $job->delay(now()->addSeconds($delaySeconds + 3));
            }
        }
    }
}
