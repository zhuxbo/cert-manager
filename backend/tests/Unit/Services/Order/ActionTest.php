<?php

use App\Exceptions\ApiResponseException;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\CreatesTestData;

uses(Tests\TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->service = app(Action::class);
    $this->user = User::factory()->create();
    $this->product = Product::factory()->create();
});

/**
 * 断言 ApiResponseException 包含指定消息
 */
function expectOrderApiError(Closure $callback, string $expectedMsg): void
{
    try {
        $callback();
        test()->fail('期望抛出 ApiResponseException 但未抛出');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        expect($response['code'])->toBe(0);
        expect($response['msg'])->toContain($expectedMsg);
    }
}

/**
 * 断言 ApiResponseException code=1（success）
 */
function expectOrderApiSuccess(Closure $callback): array
{
    try {
        $callback();
        test()->fail('期望抛出 ApiResponseException 但未抛出');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        expect($response['code'])->toBe(1);

        return $response;
    }

    return [];
}

/**
 * 创建订单 + 证书，证书状态可控，并将 latest_cert_id 挂上
 */
function createOrderWithCertForRevoke(string $certStatus, array $orderOverrides = []): array
{
    $order = Order::factory()->create(array_merge([
        'user_id' => test()->user->id,
        'product_id' => test()->product->id,
    ], $orderOverrides));

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => $certStatus,
    ]);

    $order->update(['latest_cert_id' => $cert->id]);
    $order->refresh();

    return [$order, $cert];
}

// ==================== revokeCancel ====================

test('revokeCancel cancelling 订单成功：cert.status=approving、cancel task 删除、sync task 创建', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForRevoke('cancelling');

    // 模拟延时 cancel 任务存在（commitCancel 创建的）
    $cancelTask = Task::create([
        'order_id' => $order->id,
        'action' => 'cancel',
        'status' => 'executing',
        'source' => 'admin',
        'started_at' => now()->addSeconds(120),
    ]);

    $response = expectOrderApiSuccess(fn () => $this->service->revokeCancel($order->id));

    expect($response['code'])->toBe(1);
    expect($cert->fresh()->status)->toBe('approving');

    // cancel 任务被删除
    expect(Task::where('id', $cancelTask->id)->exists())->toBeFalse();

    // sync 任务被创建
    $syncTask = Task::where('order_id', $order->id)
        ->where('action', 'sync')
        ->where('status', 'executing')
        ->first();
    expect($syncTask)->not->toBeNull();
});

test('revokeCancel active 订单报错：订单不在取消中状态', function () {
    [$order, $cert] = createOrderWithCertForRevoke('active');

    expectOrderApiError(
        fn () => $this->service->revokeCancel($order->id),
        '订单不在取消中状态'
    );

    // 状态未变化，无任何 task 创建
    expect($cert->fresh()->status)->toBe('active');
    expect(Task::where('order_id', $order->id)->count())->toBe(0);
});

test('revokeCancel 不存在的订单报错：订单或相关数据不存在', function () {
    expectOrderApiError(
        fn () => $this->service->revokeCancel(999999),
        '订单或相关数据不存在'
    );
});

test('revokeCancel 成功后可再次 commitCancel（状态机闭环）', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForRevoke('cancelling');

    // 第一步：撤回取消 → approving
    expectOrderApiSuccess(fn () => $this->service->revokeCancel($order->id));
    expect($cert->fresh()->status)->toBe('approving');

    // 第二步：再次发起取消 → cancelling（refund_period 足够）
    test()->product->update(['refund_period' => 30]);
    expectOrderApiSuccess(fn () => $this->service->commitCancel($order->id));
    expect($cert->fresh()->status)->toBe('cancelling');
});

// ==================== 通用辅助 ====================

/**
 * 创建订单 + 证书，状态/动作/字段均可覆盖（用于 Action 单元测试，与其他测试文件隔离）
 */
function createOrderWithCertForAction(string $certStatus, array $orderOverrides = [], array $certOverrides = []): array
{
    $order = Order::factory()->create(array_merge([
        'user_id' => test()->user->id,
        'product_id' => test()->product->id,
    ], $orderOverrides));

    $cert = Cert::factory()->create(array_merge([
        'order_id' => $order->id,
        'status' => $certStatus,
    ], $certOverrides));

    $order->update(['latest_cert_id' => $cert->id]);
    $order->refresh();

    return [$order, $cert];
}

// ==================== cancelPending ====================

test('cancelPending pending + action=new + amount>0：cert.status=cancelled + cancelled_at + 退费交易创建', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction(
        'pending',
        ['amount' => '30.00'],
        ['action' => 'new', 'amount' => '30.00'],
    );

    // 先造一个 order 类型交易，让 getCancelTransaction 能匹配金额（-30）
    Transaction::create([
        'user_id' => $this->user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-30.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    // cancelPending 本身不调 success()，直接调用不抛异常即为成功
    $this->service->cancelPending($order->id);

    expect($cert->fresh()->status)->toBe('cancelled');
    expect($order->fresh()->cancelled_at)->not->toBeNull();

    // 退费 cancel 交易已创建
    $tx = Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->amount)->toBe(30.0);
});

test('cancelPending pending + action=reissue + cert.amount>0：退费交易 + purchased_count 回退 + latest_cert_id 回落', function () {
    Queue::fake();

    // 当前 reissue 订单（先建订单和当前 reissue cert）
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'purchased_standard_count' => 3,
        'purchased_wildcard_count' => 1,
    ]);

    // last_cert 单独挂在同一 order（cancelPending 只读其 status / id，不关心 orders.latest_cert_id）
    $lastCert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'cancelled',
        'action' => 'new',
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'pending',
        'action' => 'reissue',
        'amount' => '50.00',
        'last_cert_id' => $lastCert->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 先造一个上次 order 类型交易（金额 -50）
    Transaction::create([
        'user_id' => $this->user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-50.00',
        'standard_count' => 1,
        'wildcard_count' => 1,
    ]);

    $this->service->cancelPending($order->id);

    // 退费 cancel 交易已创建，金额 +50
    $cancelTx = Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->first();
    expect($cancelTx)->not->toBeNull();
    expect((float) $cancelTx->amount)->toBe(50.0);

    // purchased_count 回退（原 3/1 - 1/1 = 2/0）
    $order->refresh();
    expect($order->purchased_standard_count)->toBe(2);
    expect($order->purchased_wildcard_count)->toBe(0);

    // latest_cert_id 回落到 last_cert_id
    expect($order->latest_cert_id)->toBe($lastCert->id);

    // 当前 cert 被删除
    expect(Cert::where('id', $cert->id)->exists())->toBeFalse();

    // 上个证书恢复 active
    expect($lastCert->fresh()->status)->toBe('active');
});

test('cancelPending pending + action=renew + cert.amount>0：退费交易 + last_cert_id 清空（释放 UNIQUE 槽位）+ 上个证书恢复 active', function () {
    Queue::fake();

    // 源订单 A 与源证书 X（用户首次申请的证书，处于 renewed 状态——已被续费）
    $sourceOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);
    $sourceCert = Cert::factory()->create([
        'order_id' => $sourceOrder->id,
        'status' => 'renewed',
        'action' => 'new',
    ]);
    $sourceOrder->update(['latest_cert_id' => $sourceCert->id]);

    // 续费订单 B 与续费证书 X'（pending、已扣费、last_cert_id 指向源证书）
    $renewOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'amount' => '40.00',
    ]);
    $renewCert = Cert::factory()->create([
        'order_id' => $renewOrder->id,
        'status' => 'pending',
        'action' => 'renew',
        'amount' => '40.00',
        'last_cert_id' => $sourceCert->id,
    ]);
    $renewOrder->update(['latest_cert_id' => $renewCert->id]);

    // 续费扣费 transaction（amount=-40，让 getCancelTransaction 配对）
    Transaction::create([
        'user_id' => $this->user->id,
        'type' => 'order',
        'transaction_id' => $renewOrder->id,
        'amount' => '-40.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    $this->service->cancelPending($renewOrder->id);

    // 续费 cert 标记为 cancelled
    expect($renewCert->fresh()->status)->toBe('cancelled');

    // 关键断言：last_cert_id 必须被清空，否则源证书无法再次续费（撞 UNIQUE）
    expect($renewCert->fresh()->last_cert_id)->toBeNull();

    // 源证书恢复 active
    expect($sourceCert->fresh()->status)->toBe('active');

    // 退款交易已创建
    $cancelTx = Transaction::where('transaction_id', $renewOrder->id)->where('type', 'cancel')->first();
    expect($cancelTx)->not->toBeNull();
    expect((float) $cancelTx->amount)->toBe(40.0);

    // 回归：源证书可以再次发起续费而不撞 UNIQUE
    $secondRenewOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);
    $secondRenewCert = Cert::factory()->create([
        'order_id' => $secondRenewOrder->id,
        'status' => 'pending',
        'action' => 'renew',
        'last_cert_id' => $sourceCert->id,
    ]);
    expect($secondRenewCert->id)->not->toBeNull();
});

test('cancelPending 非 pending 状态报错：锁内二次校验拦住并发退款（回归 P1 审查）', function () {
    Queue::fake();
    // 构造一个已 cancelled 的订单（模拟并发场景下第一个请求完成后的状态）
    [$order, $cert] = createOrderWithCertForAction(
        'cancelled',
        ['amount' => '100.00'],
        ['action' => 'reissue', 'amount' => '100.00', 'last_cert_id' => null],
    );

    // 第二个请求拿到锁后应当看到 status=cancelled，不再进入 reissue/new 退款分支
    expectOrderApiError(
        fn () => $this->service->cancelPending($order->id),
        '订单状态不是待提交'
    );

    // 无重复退款交易
    expect(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(0);
});

test('cancelPending 连续第二次调用报错：基础回归（证明 status 锁内校验生效）', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction(
        'pending',
        ['amount' => '0.00'],
        ['action' => 'new', 'amount' => '0.00'],
    );

    // 第一次成功（amount=0 不创建 Transaction）
    $this->service->cancelPending($order->id);
    expect($cert->fresh()->status)->toBe('cancelled');

    // 第二次：锁内 status 检查拦住
    expectOrderApiError(
        fn () => $this->service->cancelPending($order->id),
        '订单状态不是待提交'
    );
});

// ==================== commitCancel ====================

test('commitCancel active + refund_period=30：cert.status=cancelling + 创建 cancel task', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $cert] = createOrderWithCertForAction('active');

    expectOrderApiSuccess(fn () => $this->service->commitCancel($order->id));

    expect($cert->fresh()->status)->toBe('cancelling');

    $cancelTask = Task::where('order_id', $order->id)
        ->where('action', 'cancel')
        ->where('status', 'executing')
        ->first();
    expect($cancelTask)->not->toBeNull();
});

test('commitCancel active + refund_period=0：报错"订单已超过 0 天不能取消"', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 0]);

    // 订单创建时间往前 1 小时，already > 0 天
    [$order, $cert] = createOrderWithCertForAction('active', [
        'created_at' => now()->subHour(),
    ]);

    expectOrderApiError(
        fn () => $this->service->commitCancel($order->id),
        '订单已超过 0 天不能取消'
    );

    // 状态未变
    expect($cert->fresh()->status)->toBe('active');
    expect(Task::where('order_id', $order->id)->where('action', 'cancel')->count())->toBe(0);
});

test('commitCancel pending：委派 cancelPending（smoke，锁路径可达）', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction(
        'pending',
        ['amount' => '0.00'],
        ['action' => 'new', 'amount' => '0.00'],
    );

    expectOrderApiSuccess(fn () => $this->service->commitCancel($order->id));

    expect($cert->fresh()->status)->toBe('cancelled');
});

test('commitCancel unpaid：委派 delete（订单 + 证书被删除）', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction('unpaid', [], ['action' => 'new']);

    expectOrderApiSuccess(fn () => $this->service->commitCancel($order->id));

    expect(Order::where('id', $order->id)->exists())->toBeFalse();
    expect(Cert::where('id', $cert->id)->exists())->toBeFalse();
});

test('commitCancel active 串行化回归：第二次 commitCancel 被锁内 status 校验拦住', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);
    [$order, $cert] = createOrderWithCertForAction('active');

    // 第一次成功
    expectOrderApiSuccess(fn () => $this->service->commitCancel($order->id));
    expect($cert->fresh()->status)->toBe('cancelling');

    // 第二次：预检 $status === 'cancelling' 分支先报错
    // （本用例验证外层 status 兜底；锁内二次校验更进一步在真正并发场景生效）
    expectOrderApiError(
        fn () => $this->service->commitCancel($order->id),
        '订单取消中'
    );

    // cancel task 只应存在一个
    expect(Task::where('order_id', $order->id)->where('action', 'cancel')->count())->toBe(1);
});

// ==================== batchRevokeCancel ====================

test('batchRevokeCancel 3 个全 cancelling 订单：全部 cert.status=approving + 全部 sync task 创建', function () {
    Queue::fake();
    [$order1, $cert1] = createOrderWithCertForRevoke('cancelling');
    [$order2, $cert2] = createOrderWithCertForRevoke('cancelling');
    [$order3, $cert3] = createOrderWithCertForRevoke('cancelling');

    // 模拟每个订单都有延时 cancel 任务
    foreach ([$order1, $order2, $order3] as $o) {
        Task::create([
            'order_id' => $o->id,
            'action' => 'cancel',
            'status' => 'executing',
            'source' => 'admin',
            'started_at' => now()->addSeconds(120),
        ]);
    }

    expectOrderApiSuccess(fn () => $this->service->batchRevokeCancel([$order1->id, $order2->id, $order3->id]));

    expect($cert1->fresh()->status)->toBe('approving');
    expect($cert2->fresh()->status)->toBe('approving');
    expect($cert3->fresh()->status)->toBe('approving');

    // 所有 cancel 任务被删除
    expect(Task::where('action', 'cancel')->count())->toBe(0);

    // 所有 sync 任务被创建
    foreach ([$order1, $order2, $order3] as $o) {
        expect(Task::where('order_id', $o->id)->where('action', 'sync')->where('status', 'executing')->count())->toBe(1);
    }
});

test('batchRevokeCancel 混入 1 个 active 订单：前置过滤跳过非 cancelling，其余正常处理', function () {
    Queue::fake();
    [$order1, $cert1] = createOrderWithCertForRevoke('cancelling');
    [$order2, $cert2] = createOrderWithCertForRevoke('active');
    [$order3, $cert3] = createOrderWithCertForRevoke('cancelling');

    expectOrderApiSuccess(fn () => $this->service->batchRevokeCancel([$order1->id, $order2->id, $order3->id]));

    // cancelling 的两个被处理，active 的跳过
    expect($cert1->fresh()->status)->toBe('approving');
    expect($cert2->fresh()->status)->toBe('active');
    expect($cert3->fresh()->status)->toBe('approving');

    // 只有 cancelling 的两个创建了 sync task
    expect(Task::where('order_id', $order1->id)->where('action', 'sync')->count())->toBe(1);
    expect(Task::where('order_id', $order2->id)->where('action', 'sync')->count())->toBe(0);
    expect(Task::where('order_id', $order3->id)->where('action', 'sync')->count())->toBe(1);
});

test('batchRevokeCancel 全部非 cancelling：报错"没有可以撤销的订单"', function () {
    Queue::fake();
    [$order1, $cert1] = createOrderWithCertForRevoke('active');
    [$order2, $cert2] = createOrderWithCertForRevoke('pending');

    expectOrderApiError(
        fn () => $this->service->batchRevokeCancel([$order1->id, $order2->id]),
        '没有可以撤销的订单'
    );

    // 状态未变
    expect($cert1->fresh()->status)->toBe('active');
    expect($cert2->fresh()->status)->toBe('pending');
});

test('batchRevokeCancel 空数组：报错"没有可以撤销的订单"', function () {
    expectOrderApiError(
        fn () => $this->service->batchRevokeCancel([]),
        '没有可以撤销的订单'
    );
});

test('batchRevokeCancel 支持逗号分隔字符串入参', function () {
    Queue::fake();
    [$order1, $cert1] = createOrderWithCertForRevoke('cancelling');
    [$order2, $cert2] = createOrderWithCertForRevoke('cancelling');

    expectOrderApiSuccess(fn () => $this->service->batchRevokeCancel("$order1->id,$order2->id"));

    expect($cert1->fresh()->status)->toBe('approving');
    expect($cert2->fresh()->status)->toBe('approving');
});

// ==================== batchCommitCancel ====================

test('batchCommitCancel active 分支：锁内二次校验 status + 创建 cancel task（回归 P1 审查）', function () {
    Queue::fake();
    // refund_period 要足够长
    test()->product->update(['refund_period' => 30]);

    [$order1, $cert1] = createOrderWithCertForAction('active');
    [$order2, $cert2] = createOrderWithCertForAction('active');

    expectOrderApiSuccess(fn () => $this->service->batchCommitCancel([$order1->id, $order2->id]));

    expect($cert1->fresh()->status)->toBe('cancelling');
    expect($cert2->fresh()->status)->toBe('cancelling');

    // 每个订单一条 cancel task
    expect(Task::where('order_id', $order1->id)->where('action', 'cancel')->count())->toBe(1);
    expect(Task::where('order_id', $order2->id)->where('action', 'cancel')->count())->toBe(1);
});

test('batchCommitCancel active 分支锁内 status 变化：静默跳过（不破坏批量其他 id）', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order1, $cert1] = createOrderWithCertForAction('active');
    [$order2, $cert2] = createOrderWithCertForAction('active');

    // 模拟 order1 在 batchCommitCancel 预查后被改成 cancelled（并发场景）
    // 实际单线程测试里：直接操纵 DB 状态让锁内检查触发 return
    $cert1->update(['status' => 'cancelled']);

    expectOrderApiSuccess(fn () => $this->service->batchCommitCancel([$order1->id, $order2->id]));

    // order1 不被误改（锁内检测 status !== active/approving/processing 直接 return）
    expect($cert1->fresh()->status)->toBe('cancelled');
    expect(Task::where('order_id', $order1->id)->where('action', 'cancel')->count())->toBe(0);

    // order2 正常处理
    expect($cert2->fresh()->status)->toBe('cancelling');
    expect(Task::where('order_id', $order2->id)->where('action', 'cancel')->count())->toBe(1);
});
