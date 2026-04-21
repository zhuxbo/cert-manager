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
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Action
{
    use ApiResponse;

    /**
     * 创建 ACME 订单（unpaid 状态）
     */
    public function new(array $params): void
    {
        $acme = $this->createOrder($params);
        $this->success(['order_id' => $acme->id]);
    }

    /**
     * 支付订单 — 扣费，状态 → pending，默认自动入队 commit_acme
     */
    public function pay(int $acmeId, bool $autoCommit = true): void
    {
        $acme = Acme::findOrFail($acmeId);
        $this->payOrder($acme);

        if ($autoCommit) {
            $existing = Task::where('order_id', $acmeId)
                ->where('action', 'commit_acme')
                ->where('status', 'executing')
                ->exists();
            if (! $existing) {
                $this->createTasks([$acmeId], 'commit_acme');
            }
        }

        $this->success();
    }

    /**
     * 批量支付订单
     *
     * 逐条独立事务执行，单条失败收集到 errors，不影响其他。
     * 仅处理 unpaid 状态订单，非 unpaid 静默过滤。
     */
    public function batchPay(array $acmeIds): void
    {
        $acmeIds = array_map('intval', $acmeIds);

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
        $acmeIds = array_map('intval', $acmeIds);

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
        $acmeIds = array_map('intval', $acmeIds);

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
        $acmeIds = array_map('intval', $acmeIds);

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
        $acmeIds = array_map('intval', $acmeIds);

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
        $acme = DB::transaction(function () use ($acmeId) {
            $locked = Acme::where('id', $acmeId)->lock()->firstOrFail();

            return $this->commitOrder($locked);
        });

        $acme->makeVisible('eab_hmac');
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

        $acme->makeVisible('eab_hmac');
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
     */
    public function commitCancel(int $acmeId): void
    {
        DB::transaction(function () use ($acmeId) {
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
     * 下游 API（/api/acme/cancel）场景使用；Web 入口仍走 commitCancel 延时流程，保留撤回窗口。
     * 并发安全：整个流程（状态校验 + 上游调用 + 退费 + 状态更新）在同一事务内持有 acme 行级锁，
     * 避免与 revokeCancel 产生"退费成功 + 订单被吊销"的双重损害。
     */
    public function cancelNow(int $acmeId): void
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
        DB::transaction(function () use ($acmeId) {
            // 锁顺序 1：先锁 task（与 TaskJob 一致，避免 task↔acme 循环等待死锁）
            Task::where('order_id', $acmeId)
                ->where('action', 'cancel_acme')
                ->whereIn('status', ['executing', 'stopped'])
                ->lockForUpdate()
                ->get();

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
        // 10秒内不重复向上请求
        $cacheKey = "acme_sync_$acmeId";
        if (Cache::get($cacheKey)) {
            if ($force) {
                return;
            }
            $this->success();
        }

        $acme = Acme::find($acmeId);

        if (! $acme || ! $acme->api_id) {
            if ($force) {
                return;
            }
            $this->error($acme ? '订单尚未提交到上游' : '订单不存在');
        }

        $result = (new Api)->get($acme->id);

        $data = $result['data'] ?? [];
        $updateData = [];
        $syncableStatuses = [Acme::STATUS_ACTIVE, Acme::STATUS_REVOKED, Acme::STATUS_EXPIRED, Acme::STATUS_CANCELLED];
        if (isset($data['status']) && in_array($data['status'], $syncableStatuses)) {
            $updateData['status'] = $data['status'];
            // 上游已取消/吊销且本地尚未记录取消时间 → 用当前时间补记（正式取消时间）
            if (in_array($data['status'], [Acme::STATUS_CANCELLED, Acme::STATUS_REVOKED]) && ! $acme->cancelled_at) {
                $updateData['cancelled_at'] = now();
            }
        }
        if (isset($data['vendor_id'])) {
            $updateData['vendor_id'] = $data['vendor_id'];
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

        if (! empty($data['directory_url'])) {
            $this->cacheDirectoryUrl((string) ($acme->product->ca ?? ''), (string) $data['directory_url']);
        }

        Cache::set($cacheKey, time(), 10);

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
     */
    private function createOrder(array $params): Acme
    {
        $product = Product::where('id', $params['product_id'] ?? 0)
            ->where('product_type', Product::TYPE_ACME)
            ->first();

        if (! $product) {
            $this->error('产品不存在或不支持 ACME');
        }

        $period = (int) ($params['period'] ?? 0);
        if (! in_array($period, $product->periods)) {
            $this->error('无效的购买时长');
        }

        [$standardCount, $wildcardCount] = $this->resolveDomainCounts($product);

        // 计算订单金额
        $amount = OrderUtil::getLatestCertAmount(
            ['user_id' => $params['user_id'], 'product_id' => $params['product_id'], 'period' => $period, 'purchased_standard_count' => 0, 'purchased_wildcard_count' => 0],
            ['standard_count' => $standardCount, 'wildcard_count' => $wildcardCount, 'action' => 'new'],
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
            'refer_id' => bin2hex(random_bytes(16)),
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
        $wildcardMax = (int) ($product->wildcard_max ?? 0);

        if ($standardMax >= 1 && $wildcardMax === 0) {
            return [1, 0];
        }
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
        $ca = $this->normalizeCa((string) ($acme->product->ca ?? ''));
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
     * 写入/刷新 directory URL 缓存（Cache::forever，按 CA 聚合）
     */
    private function cacheDirectoryUrl(string $ca, ?string $url): void
    {
        $ca = $this->normalizeCa($ca);
        if ($ca === '' || ! $url) {
            return;
        }

        Cache::forever($this->directoryUrlCacheKey($ca), $url);
    }

    private function directoryUrlCacheKey(string $ca): string
    {
        return "acme_directory_url:$ca";
    }

    private function normalizeCa(string $ca): string
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
     * 对齐上游系统 /acme/new 接口：只传 customer / product_code / refer_id
     */
    private function commitOrder(Acme $acme): Acme
    {
        if ($acme->status !== Acme::STATUS_PENDING) {
            $this->error('订单状态不是待提交');
        }

        $product = $acme->product;
        $user = $acme->user;

        if (! $user->email) {
            $this->error('用户邮箱缺失，无法提交 ACME 订单');
        }

        // source 用于 Api 路由到对应 source 实现类（上游接收端会忽略多余字段）
        $data = [
            'source' => $product->source,
            'customer' => $user->email,
            'product_code' => $product->code,
            'plus' => (int) $acme->plus,
            'refer_id' => $acme->refer_id,
        ];

        try {
            $result = (new Api)->new($data);
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
     */
    private function refund(Acme $acme): void
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
     * 批量创建 Task 并 dispatch TaskJob（started_at 可选延时）
     */
    private function createTasks(array $acmeIds, string $action, int $delaySeconds = 0): void
    {
        $startedAt = $delaySeconds > 0 ? now()->addSeconds($delaySeconds) : now();
        $source = getControllerCategory();

        foreach ($acmeIds as $id) {
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
                $job->delay($startedAt);
            }
        }
    }
}
