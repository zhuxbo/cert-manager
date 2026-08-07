<?php

use App\Exceptions\ApiResponseException;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

/**
 * 在当前测试事务内预置 autoRefundOnSync 设置项
 * Setting::setValue 依赖 SettingGroup + Setting 记录，测试 DB 仅跑 migration 不跑 seeder
 */
function setupAutoRefundSetting(bool $enabled = false): void
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'description' => null, 'weight' => 1]
    );

    Setting::firstOrCreate(
        ['group_id' => $group->id, 'key' => 'autoRefundOnSync'],
        ['type' => 'boolean', 'options' => null, 'is_multiple' => 0, 'value' => false, 'description' => '同步退款开关', 'weight' => 8]
    );

    Setting::setValue('site', 'autoRefundOnSync', $enabled);
}

beforeEach(function () {
    setupAutoRefundSetting(false);
});

// ==================== 辅助函数 ====================

/**
 * 预置 order 类型扣费 Transaction，供 getCancelTransaction 计算退款额
 */
function createOrderTransaction(int $userId, int $orderId, string $amount = '-100.00'): void
{
    Transaction::create([
        'user_id' => $userId,
        'type' => 'order',
        'transaction_id' => $orderId,
        'amount' => $amount,
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
}

/**
 * 调 sync 并捕获 ApiResponseException（非 force 模式 success() 会抛）
 */
function syncOrder(Action $action, int $orderId, bool $force = false): void
{
    try {
        $action->sync($orderId, $force);
    } catch (ApiResponseException $e) {
        // code=1 是正常成功返回，code=0 是错误
        if ($e->getApiResponse()['code'] !== 1) {
            throw $e;
        }
    }
}

/**
 * 清除 checkDuplicate 缓存，让同一 orderId 可以多次 sync
 */
function clearSyncDuplicateCache(int $orderId): void
{
    $key = 'sync_'.md5(json_encode([$orderId]));
    Cache::forget($key);
}

/**
 * Mock Order\Api 返回指定上游 status
 *
 * Action::__construct 通过 app(Api::class) 获取实例，容器绑定生效。
 */
function mockOrderApiGet(string $upstreamStatus): MockInterface
{
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('get')
        ->andReturn(['code' => 1, 'data' => ['status' => $upstreamStatus]]);

    app()->instance(Api::class, $mock);

    return $mock;
}

// ==================== 测试用例 ====================

test('#1 开关关 + 上游 cancelled + action=new：cert.status 变为 cancelled，无 cancel Transaction', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'test-api-id-1']);

    // order Transaction 扣 100：balance 100 → 0
    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
    // 开关关：余额仍为 0（无退款）
    expect($user->refresh()->balance)->toBe('0.00');
});

test('#2 开关开 + 上游 cancelled + action=new + cert.status=processing：触发退款', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 100，createOrderTransaction(-100) 扣费 → balance=0，退款+100 → balance=100
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'test-api-id-2']);

    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    $lockedTaskTypes = [];
    DB::listen(function ($query) use (&$lockedTaskTypes) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'from `tasks`') && str_contains($sql, 'for update')) {
            $lockedTaskTypes = array_values(array_intersect(
                ['commit', 'sync', 'revalidate'],
                array_map('strval', $query->bindings),
            ));
        }
    });

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
    expect($lockedTaskTypes)->toBe(['commit', 'sync', 'revalidate']);
});

test('#3 开关开 + 上游 cancelled + action=new + cert.status=approving：触发退款', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 100，createOrderTransaction(-100) 扣费 → balance=0，退款+100 → balance=100
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'approving', 'action' => 'new', 'api_id' => 'test-api-id-3']);

    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
});

test('#4 开关开 + 上游 cancelled + action=new + cert.status=cancelling：触发退款', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 100，createOrderTransaction(-100) 扣费 → balance=0，退款+100 → balance=100
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'cancelling', 'action' => 'new', 'api_id' => 'test-api-id-4']);

    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    // cancelling 不在 sync 非 force 模式允许的 {processing, approving, active}，用 force=true
    syncOrder(app(Action::class), $order->id, true);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
    // cancelling 状态下可能存在 cancel task，refundForSyncedCancel 不主动删除（避免锁序倒置死锁）。
    // 残留 task 被 TaskJob 调用时 Action::cancel() 内 status===cancelled 检查会抛错回滚，资金安全。
});

test('#5 开关开 + 上游 cancelled + action=renew + cert.status=processing：触发退款', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 80，createOrderTransaction(-80) 扣费 → balance=0，退款+80 → balance=80
    $user = $this->createTestUser(['balance' => '80.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $sourceOrder = $this->createTestOrder($user, $product);
    $sourceCert = $this->createTestCert($sourceOrder, [
        'status' => 'renewed',
        'action' => 'new',
        'common_name' => 'sync-renew-source.example.com',
        'expires_at' => now()->addDays(60),
    ]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '80.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, [
        'status' => 'processing',
        'action' => 'renew',
        'api_id' => 'test-api-id-5',
        'last_cert_id' => $sourceCert->id,
    ]);

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    createOrderTransaction($user->id, $order->id, '-80.00');
    mockOrderApiGet('cancelled');

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('80.00');

    $intents = collect($captured)
        ->filter(fn ($intent) => $intent->code === 'cert_renew_cancelled')
        ->values();
    expect($intents)->toHaveCount(1);
    expect($intents[0]->notifiableId)->toBe($user->id)
        ->and($intents[0]->context)->toMatchArray([
            'common_name' => 'sync-renew-source.example.com',
            'order_id' => $order->id,
            'action' => '续费',
            'product_type' => Product::TYPE_SSL,
        ]);
});

test('#6 开关开 + 上游 cancelled + action=reissue：触发增量退款 + cert_renew_cancelled 通知（前驱保持 reissued 不恢复）', function () {
    // ③ 修复：reissue 原被四条件 gate 排除、恒穿透通用写回 → 增量费用不退、无通知（旧错误行为，本用例即修此）。
    // 现纳入 refundForSyncedCancel 按 action 分流走增量退款口径（对齐 cancelLocked reissue：只退当次增量，
    // 前驱不恢复保持 reissued）+ 发 cert_renew_cancelled 一次性通知。
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 充值 120，原始扣 -100 + reissue 增量扣 -20 → balance=0；取消退【增量 20】→ balance=20
    $user = $this->createTestUser(['balance' => '120.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '120.00',
        'purchased_standard_count' => 2,
        'purchased_wildcard_count' => 0,
    ]);
    // 前驱证书（同订单，被 reissue，保持 reissued 终态）
    $oldCert = $this->createTestCert($order, [
        'status' => 'reissued',
        'action' => 'new',
        'common_name' => 'sync-reissue-source.example.com',
        'expires_at' => now()->addDays(60),
    ]);
    // reissue 接替证书（同订单，last_cert_id 指向前驱、amount=当次增量）
    $reissueCert = $this->createTestCert($order, [
        'status' => 'processing',
        'action' => 'reissue',
        'api_id' => 'test-api-id-6',
        'amount' => '20.00',
        'last_cert_id' => $oldCert->id,
        'common_name' => 'sync-reissue-source.example.com',
    ]);

    // 原始扣费 -100 + reissue 增量扣费 -20（最后一笔，供增量口径；误用 getCancelTransaction 求和会退 120）
    createOrderTransaction($user->id, $order->id, '-100.00');
    Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-20.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    mockOrderApiGet('cancelled');

    syncOrder(app(Action::class), $order->id);

    expect($reissueCert->fresh()->status)->toBe('cancelled');
    // 只退增量 20（非全额 120）
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect((float) Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->value('amount'))->toBe(20.0);
    expect($user->refresh()->balance)->toBe('20.00');
    // 前驱不恢复（保持 reissued 终态、脱离监控）
    expect($oldCert->fresh()->status)->toBe('reissued');

    // cert_renew_cancelled 通知（前驱脱监控止血）
    $intents = collect($captured)->filter(fn ($intent) => $intent->code === 'cert_renew_cancelled')->values();
    expect($intents)->toHaveCount(1);
    expect($intents[0]->context)->toMatchArray([
        'common_name' => 'sync-reissue-source.example.com',
        'order_id' => $order->id,
        'action' => '重签',
        'product_type' => Product::TYPE_SSL,
    ]);
});

test('#7 开关开 + 上游 cancelled + cert.status=active（非过渡态）：cert.status 变 cancelled 但无退款', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 200，下单扣 100 → balance=100；active 状态不在过渡态，不退款
    $user = $this->createTestUser(['balance' => '200.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'active', 'action' => 'new', 'api_id' => 'test-api-id-7']);

    // order Transaction 扣 100：balance 200 → 100
    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    // active 在 sync 非 force 允许列表内
    syncOrder(app(Action::class), $order->id);

    // sync 默认路径仍会把 status 改为 cancelled
    expect($order->latestCert()->first()->status)->toBe('cancelled');
    // 但不触发退款（active 不在过渡态集合）
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
    // 余额保持 100（无退款）
    expect($user->refresh()->balance)->toBe('100.00');
});

test('#8 开关开 + 幂等：已是 cancelled + force sync 不重复创建 Transaction', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 100，createOrderTransaction(-100) 扣费 → balance=0，退款+100 → balance=100
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'test-api-id-8']);

    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    // 第一次 sync：退款并改 status=cancelled
    syncOrder(app(Action::class), $order->id);
    expect($user->refresh()->balance)->toBe('100.00');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);

    // 清除 checkDuplicate 缓存，允许同一 orderId 再次 sync
    clearSyncDuplicateCache($order->id);

    // 此时 cert.status=cancelled，force=true 会 unset data.status（L464）
    // => $hasStatusChanged=false => 退款分支不触发 => 不会重复创建 Transaction
    syncOrder(app(Action::class), $order->id, true);

    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
});

test('#9 开关开 + 0 元订单：Transaction amount=0 短路，无 cancel Transaction 创建', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    $user = $this->createTestUser(['balance' => '0.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '0.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'test-api-id-9']);

    // 0 元订单不预置 order Transaction（或 amount=0 Transaction 会被钩子短路）
    // getCancelTransaction 会计算 transactionAmount=0，导致 Transaction::create 被 creating 钩子短路
    mockOrderApiGet('cancelled');

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
});

test('#10 并发幂等：连续两次 sync 仅 1 笔 cancel Transaction', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 100，createOrderTransaction(-100) 扣费 → balance=0，退款+100 → balance=100
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'test-api-id-10']);

    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    // 第一次 sync：退款
    syncOrder(app(Action::class), $order->id);
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);

    // 第二次 sync（未清 cache）：checkDuplicate 拦截，直接返回，不重复创建
    syncOrder(app(Action::class), $order->id);

    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
});

test('#11 开关开 + 已有 cancel Transaction + cert.status=cancelling：sync 检测防重，仅改 cert.status', function () {
    Setting::setValue('site', 'autoRefundOnSync', true);

    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'cancelling', 'action' => 'new', 'api_id' => 'test-api-id-11']);

    // 预置 order Transaction（原始扣费）
    createOrderTransaction($user->id, $order->id, '-100.00');

    // 预置 cancel Transaction（例如 PurgeCommand 已退款）
    Transaction::create([
        'user_id' => $user->id,
        'type' => 'cancel',
        'transaction_id' => $order->id,
        'amount' => '100.00',
        'standard_count' => -1,
        'wildcard_count' => 0,
    ]);

    // 验证余额已被退款回来（100 充值 - 100 扣费 + 100 退款 = 100）
    expect($user->refresh()->balance)->toBe('100.00');

    mockOrderApiGet('cancelled');

    // cancelling 走 force=true（非 force 模式只允许 processing/approving/active）
    syncOrder(app(Action::class), $order->id, true);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    // 仍只有 1 笔 cancel Transaction
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    // 余额不再变化
    expect($user->refresh()->balance)->toBe('100.00');
});

test('#13 残留 cancel task 防双退：sync 自动退款置 cancelled 后再执行 cancel() 不二次退款', function () {
    // 还原审核 #59 杀手场景：
    //   refundForSyncedCancel 在锁内退款并置 cancelled，但刻意不删残留 cancel task（锁序原因）。
    //   该残留 task 随后被 TaskJob 唤醒，调用 Action::cancel()。
    // 断言：cancel() 在锁内撞上 status===cancelled 校验抛错回滚，
    //   不产生第二条 cancel Transaction、余额只退一次。
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 充值 100 → 下单扣 100（balance=0）→ sync 退款 +100（balance=100）
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    // cancelling 状态正是「已 commitCancel、残留 cancel task」的真实场景
    $this->createTestCert($order, ['status' => 'cancelling', 'action' => 'new', 'api_id' => 'test-api-id-13']);

    createOrderTransaction($user->id, $order->id, '-100.00');

    // 模拟 commitCancel 留下的残留 cancel task（延时未到，TaskJob 尚未消费）
    // tasks 表无 user_id 列，按 order_id 关联
    Task::create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'attempts' => 0,
    ]);

    mockOrderApiGet('cancelled');

    // 第一步：sync 走 refundForSyncedCancel —— 退款 + 置 cancelled，残留 cancel task 不被删
    syncOrder(app(Action::class), $order->id, true);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
    // 残留 cancel task 仍在（refundForSyncedCancel 只删 commit/sync/revalidate）
    expect(Task::where('order_id', $order->id)->where('action', 'cancel')->count())->toBe(1);

    // 第二步：模拟 TaskJob 唤醒残留 cancel task → 调 Action::cancel()
    // 锁内 L926 status===cancelled 校验抛 '订单已取消' 回滚，不走到上游/退款
    $threw = false;
    try {
        app(Action::class)->cancel($order->id);
    } catch (ApiResponseException $e) {
        $threw = true;
        expect($e->getApiResponse()['code'])->toBe(0);
        expect($e->getApiResponse()['msg'])->toBe('订单已取消');
    }
    expect($threw)->toBeTrue();

    // 核心断言：无第二条 cancel Transaction，余额只退一次
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
});

test('#14 唯一索引物理底线：cancelled 订单强行 create 第二条 cancel Transaction 被 DB 拒绝', function () {
    // 绕过锁内 status 校验（refundForSyncedCancel/cancel 内的应用层校验），
    // 直接验证 transactions_dedup_unique（迁移 2026_05_07_120000）作为防双退的物理底线：
    //   同一 (type=cancel, transaction_id) 第二条 INSERT 必被拒绝（DB 唯一冲突 或 creating 钩子二次 exists）。
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);

    // 第一条 cancel 退款：balance 100 → 200
    DB::transaction(fn () => Transaction::create([
        'user_id' => $user->id,
        'type' => 'cancel',
        'transaction_id' => $order->id,
        'amount' => '100.00',
        'standard_count' => -1,
        'wildcard_count' => 0,
    ]));
    expect($user->refresh()->balance)->toBe('200.00');

    // 第二条同 (type=cancel, transaction_id) 必被拒（应用层钩子或 DB 唯一索引），余额不再变化
    $threw = false;
    try {
        DB::transaction(fn () => Transaction::create([
            'user_id' => $user->id,
            'type' => 'cancel',
            'transaction_id' => $order->id,
            'amount' => '100.00',
            'standard_count' => -1,
            'wildcard_count' => 0,
        ]));
    } catch (Throwable $e) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('200.00');
});

test('#12 上游 revoked + action=new（plain new）+ 开关开：走 sync 默认路径不退款 + 发 cert_revoked（is_successor=false）', function () {
    // revoked 是独立于续费的重大服务中断事件：plain new 证书被 CA 吊销，用户须知悉并按需重新申请。
    // 修复前该路径零通知（仅 callback+deleteTask）；本用例锁定 revoked → cert_revoked 派发 + 不进退款分支。
    Setting::setValue('site', 'autoRefundOnSync', true);

    // 用户充值 100，下单扣 100 → balance=0；revoked 不触发 cancelled 退款分支
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct([
        'refund_period' => 30,
        'product_type' => Product::TYPE_CODESIGN,
    ]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, [
        'status' => 'active',
        'action' => 'new',
        'api_id' => 'test-api-id-12',
        'common_name' => 'revoked-plain.example.com',
        'expires_at' => '2027-01-15 08:00:00',
    ]);

    // order Transaction 扣 100：balance 100 → 0
    createOrderTransaction($user->id, $order->id, '-100.00');

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    mockOrderApiGet('revoked');

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('revoked');
    // revoked 不进退款分支（refundForSyncedCancel 只处理 cancelled）：无退款、余额仍为 0
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
    expect($user->refresh()->balance)->toBe('0.00');

    // cert_revoked 通知：主体为被吊销证书自身，plain new → is_successor=false
    $intents = collect($captured)->filter(fn ($intent) => $intent->code === 'cert_revoked')->values();
    expect($intents)->toHaveCount(1);
    expect($intents[0]->notifiableId)->toBe($user->id)
        ->and($intents[0]->context)->toMatchArray([
            'common_name' => 'revoked-plain.example.com',
            'expires_at' => '2027-01-15',
            'order_id' => $order->id,
            'is_successor' => false,
            'product_type' => Product::TYPE_CODESIGN,
        ]);
});

test('#19 上游 revoked + action=renew 有前驱：通用写回落 revoked + 发 cert_revoked（is_successor=true，前驱保持 renewed）', function () {
    // 接替单签发 active 后被 CA 吊销，前驱已 renewed 脱离 cert_expire/AutoRenew/cert_renew_stalled 三重监控 →
    // 双重静默（前驱不受监控 + 接替单吊销无告知）。cert_revoked 是唯一告知；is_successor=true 触发前驱脱监控文案。
    $user = $this->createTestUser(['balance' => '80.00']);
    $product = $this->createTestProduct([
        'refund_period' => 30,
        'product_type' => Product::TYPE_SMIME,
    ]);

    // 前驱证书（renewed 终态，跨订单）
    $sourceOrder = $this->createTestOrder($user, $product);
    $sourceCert = $this->createTestCert($sourceOrder, [
        'status' => 'renewed',
        'action' => 'new',
        'common_name' => 'revoked-successor.example.com',
        'expires_at' => now()->addDays(60),
    ]);

    // renew 接替单（last_cert_id 指向前驱），签发 active 后被上游吊销
    $order = $this->createTestOrder($user, $product, ['amount' => '80.00']);
    $this->createTestCert($order, [
        'status' => 'active',
        'action' => 'renew',
        'api_id' => 'test-api-id-19',
        'last_cert_id' => $sourceCert->id,
        'common_name' => 'revoked-successor.example.com',
        'expires_at' => '2027-02-20 00:00:00',
    ]);
    createOrderTransaction($user->id, $order->id, '-80.00');

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    mockOrderApiGet('revoked');

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('revoked');
    // revoked 不走退款/前驱恢复分支：前驱保持 renewed 不恢复
    expect($sourceCert->fresh()->status)->toBe('renewed');

    // cert_revoked 通知：接替单被吊销 → is_successor=true
    $intents = collect($captured)->filter(fn ($intent) => $intent->code === 'cert_revoked')->values();
    expect($intents)->toHaveCount(1);
    expect($intents[0]->notifiableId)->toBe($user->id)
        ->and($intents[0]->context)->toMatchArray([
            'common_name' => 'revoked-successor.example.com',
            'expires_at' => '2027-02-20',
            'order_id' => $order->id,
            'is_successor' => true,
            'product_type' => Product::TYPE_SMIME,
        ]);
});

test('#20 revoked 通知防重：revoked 落定后再次 force sync 不重复派发 cert_revoked', function () {
    // 防重由 hasStatusChanged 保证：二次 sync 终态守卫 unset data.status → hasStatusChanged=false → 不再派发。
    // 与 cert_renew_cancelled 同一防重路径（对照 #18）。
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, ['amount' => '100.00']);
    $this->createTestCert($order, [
        'status' => 'active',
        'action' => 'new',
        'api_id' => 'test-api-id-20',
        'common_name' => 'revoked-dedup.example.com',
    ]);
    createOrderTransaction($user->id, $order->id, '-100.00');

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    mockOrderApiGet('revoked');

    // 第一次：active → revoked，发通知
    syncOrder(app(Action::class), $order->id);
    // 第二次：force sync（revoked 不在非 force 允许列表），终态守卫 unset status → 不重复派发
    clearSyncDuplicateCache($order->id);
    syncOrder(app(Action::class), $order->id, true);

    $intents = collect($captured)->filter(fn ($intent) => $intent->code === 'cert_revoked')->values();
    expect($intents)->toHaveCount(1);
});

test('#15 force=true 退款分支静默返回：V1/V2 get 直调 sync(force) 不被 success 异常打断', function () {
    // Bug 回归：refundForSyncedCancel 分支原先无条件 $this->success()，未用 $force 守卫。
    // force=true（V1/V2 ApiController::get 无 try-catch 直调 sync）会被 ApiResponseException(code=1) 打断，
    // 使 get 返回空 {code:1} 而非订单数据。本用例直接调 sync(force=true) 且【不捕获异常】，
    // 修复前抛异常致测试失败、修复后静默返回；同时退款仍须正确完成。
    Setting::setValue('site', 'autoRefundOnSync', true);

    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'test-api-id-15']);

    createOrderTransaction($user->id, $order->id, '-100.00');
    mockOrderApiGet('cancelled');

    // 关键：模拟 get 的真实调用方式，不包 try-catch。修复前此处会抛 ApiResponseException(code=1)。
    app(Action::class)->sync($order->id, true);

    // 退款仍正确：cert 置 cancelled、生成 cancel Transaction、余额退回
    expect($order->latestCert()->first()->status)->toBe('cancelled');
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1);
    expect($user->refresh()->balance)->toBe('100.00');
});

// ==================== ③ 通用写回通知收口（refundForSyncedCancel 分支未覆盖的路径）====================

test('#16 开关关 + 上游 cancelled + renew 有前驱：通用写回落 cancelled + 发 cert_renew_cancelled（无退款是开关语义）', function () {
    // ③ 默认部署常态（隐藏设置 autoRefundOnSync 缺失即 false）：renew 上游取消经 sync 通用写回落 cancelled，
    // 前驱保持 renewed 脱离 cert_expire/AutoRenew/cert_renew_stalled 三重监控。补一次性通知止血。
    // 删除测试预置项，直接覆盖默认部署下该隐藏设置不存在的场景。
    Setting::where('key', 'autoRefundOnSync')->firstOrFail()->delete();
    Setting::clearAllCache();

    $user = $this->createTestUser(['balance' => '80.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);

    // 前驱证书（renewed，跨订单）
    $sourceOrder = $this->createTestOrder($user, $product);
    $sourceCert = $this->createTestCert($sourceOrder, [
        'status' => 'renewed',
        'action' => 'new',
        'common_name' => 'writeback-renew-source.example.com',
        'expires_at' => now()->addDays(50),
    ]);

    // renew 接替单
    $order = $this->createTestOrder($user, $product, ['amount' => '80.00']);
    $this->createTestCert($order, [
        'status' => 'processing',
        'action' => 'renew',
        'api_id' => 'test-api-id-16',
        'last_cert_id' => $sourceCert->id,
    ]);
    createOrderTransaction($user->id, $order->id, '-80.00'); // balance 80 → 0

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    mockOrderApiGet('cancelled');

    syncOrder(app(Action::class), $order->id);

    expect($order->latestCert()->first()->status)->toBe('cancelled');
    // 开关关：不退款（语义），余额保持 0
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
    expect($user->refresh()->balance)->toBe('0.00');
    // 但必须发一次性通知（前驱脱监控止血）
    $intents = collect($captured)->filter(fn ($intent) => $intent->code === 'cert_renew_cancelled')->values();
    expect($intents)->toHaveCount(1);
    expect($intents[0]->notifiableId)->toBe($user->id)
        ->and($intents[0]->context)->toMatchArray([
            'common_name' => 'writeback-renew-source.example.com',
            'order_id' => $order->id,
            'action' => '续费',
            'product_type' => Product::TYPE_SSL,
        ]);
});

test('#17 开关关 + 上游 cancelled + reissue 有前驱：通用写回落 cancelled + 发 cert_renew_cancelled（无退款）', function () {
    // ③ 场景 b 通知部分：开关关时 reissue 走通用写回落 cancelled、不退款（开关语义），前驱保持 reissued。
    // 补通用写回通知止血。
    $user = $this->createTestUser(['balance' => '120.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);

    $order = $this->createTestOrder($user, $product, ['amount' => '120.00']);
    $oldCert = $this->createTestCert($order, [
        'status' => 'reissued',
        'action' => 'new',
        'common_name' => 'writeback-reissue-source.example.com',
        'expires_at' => now()->addDays(50),
    ]);
    $reissueCert = $this->createTestCert($order, [
        'status' => 'processing',
        'action' => 'reissue',
        'api_id' => 'test-api-id-17',
        'amount' => '20.00',
        'last_cert_id' => $oldCert->id,
        'common_name' => 'writeback-reissue-source.example.com',
    ]);
    createOrderTransaction($user->id, $order->id, '-120.00'); // balance 120 → 0

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    mockOrderApiGet('cancelled');

    syncOrder(app(Action::class), $order->id);

    expect($reissueCert->fresh()->status)->toBe('cancelled');
    // 开关关：reissue 不退款，余额保持 0，前驱保持 reissued
    expect(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
    expect($user->refresh()->balance)->toBe('0.00');
    expect($oldCert->fresh()->status)->toBe('reissued');
    // 发一次性通知（action=重签）
    $intents = collect($captured)->filter(fn ($intent) => $intent->code === 'cert_renew_cancelled')->values();
    expect($intents)->toHaveCount(1);
    expect($intents[0]->context)->toMatchArray([
        'common_name' => 'writeback-reissue-source.example.com',
        'order_id' => $order->id,
        'action' => '重签',
        'product_type' => Product::TYPE_SSL,
    ]);
});

test('#18 通用写回通知防重：cancelled 落定后再次 force sync 不重复派发 cert_renew_cancelled', function () {
    // 防重由 hasStatusChanged 保证：二次 sync 时终态守卫 unset data.status → hasStatusChanged=false → 不再派发。
    $user = $this->createTestUser(['balance' => '80.00']);
    $product = $this->createTestProduct(['refund_period' => 30]);

    $sourceOrder = $this->createTestOrder($user, $product);
    $sourceCert = $this->createTestCert($sourceOrder, [
        'status' => 'renewed',
        'action' => 'new',
        'common_name' => 'writeback-dedup-source.example.com',
        'expires_at' => now()->addDays(50),
    ]);
    $order = $this->createTestOrder($user, $product, ['amount' => '80.00']);
    $this->createTestCert($order, [
        'status' => 'processing',
        'action' => 'renew',
        'api_id' => 'test-api-id-18',
        'last_cert_id' => $sourceCert->id,
    ]);
    createOrderTransaction($user->id, $order->id, '-80.00');

    $captured = new ArrayObject;
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $notificationCenter);

    mockOrderApiGet('cancelled');

    // 第一次：processing → cancelled，发通知
    syncOrder(app(Action::class), $order->id);
    // 第二次：force sync，cert 已 cancelled，终态守卫 unset status → 不重复派发
    clearSyncDuplicateCache($order->id);
    syncOrder(app(Action::class), $order->id, true);

    $intents = collect($captured)->filter(fn ($intent) => $intent->code === 'cert_renew_cancelled')->values();
    expect($intents)->toHaveCount(1);
});
