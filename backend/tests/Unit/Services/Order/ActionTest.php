<?php

use App\Exceptions\ApiResponseException;
use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

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

/**
 * delete 锁内 status 校验守门：模拟"另一个并发请求"已 charge 把 cert.status 从 unpaid 改为 pending，
 * 此时 delete 必须拒绝并保留订单/证书。
 *
 * 守门范围（最低保障）：
 * - 防止"完全删掉 status 校验"导致已扣费订单被误删
 * - 单线程顺序模拟：外部 update 先于 delete 调用，锁外/锁内读都看到 pending
 * - **不能区分**"锁内 vs 锁外校验"——真竞态守门要 fork 双进程，参见 tests/Feature/Concurrent/
 */
test('delete 锁内 status 校验拦住已被并发改成非 unpaid 的订单（守门）', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction('unpaid', [], ['action' => 'new']);

    // 模拟另一个并发请求（charge 路径）把 cert.status 从 unpaid 改为 pending
    Cert::where('id', $cert->id)->update(['status' => 'pending']);

    // delete 必须拒绝（commitCancel 走 unpaid 分支但内部 delete 锁内校验失败）
    expectOrderApiError(
        fn () => $this->service->delete($order->id),
        '只有待支付状态的证书可以删除'
    );

    // 订单/证书仍存在
    expect(Order::where('id', $order->id)->exists())->toBeTrue();
    expect(Cert::where('id', $cert->id)->exists())->toBeTrue();
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

// ==================== sync 终态守卫 ====================

test('sync 终态守卫（force=false TOCTOU）：锁外慢 IO 期间被并发 cancel 置 cancelled，不被上游滞后 active 复活', function () {
    Queue::fake();
    // 初始 processing：通过 force=false 分支「只有待验证/待批准/已签发才能同步」前置校验
    [$order, $cert] = createOrderWithCertForRevoke('processing');

    // mock 上游 get：返回滞后的 active，并在回调里模拟"锁外慢 IO 期间并发 cancel 置终态 + 退款"——
    // 直接改库（绕过内存 $cert），让锁内重读拿到权威 cancelled。
    // 命中的是 force=false 路径下锁内的新守卫（Action::sync unset($data['status'])），
    // 而非 force=true 才走的 462-466 行 force-unset（那条会让 $data 压根没有 status，守卫变 no-op → 假绿）。
    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('get')
        ->andReturnUsing(function () use ($cert) {
            Cert::where('id', $cert->id)->update(['status' => 'cancelled']);

            return [
                'code' => 1,
                'data' => ['status' => 'active'],
            ];
        });
    // 反射注入 protected $api，绕过上游真实 HTTP（参照 ReleaseClientTest 范式）
    $ref = new ReflectionClass($this->service);
    $prop = $ref->getProperty('api');
    $prop->setAccessible(true);
    $prop->setValue($this->service, $mockApi);

    // force=false：sync 末尾 success() 抛 ApiResponseException，用 expectOrderApiSuccess 兜住，事务已提交
    expectOrderApiSuccess(fn () => $this->service->sync($order->id, false));

    // 终态守卫生效：cert 仍 cancelled，未被上游滞后 active 覆盖（防已退款订单复活）
    expect($cert->fresh()->status)->toBe('cancelled');
});

// ==================== charge / pay 扣费核心 ====================
//
// charge() 是真金白银的最高频路径（ActionTrait::charge）。这里通过单订单
// pay($id, false)（commit=false，只扣费不提交上游）真实执行 charge，断言：
// 余额边界、admin 欠费放行、退款回正、同用户跨订单并发被 credit_limit 锁内拦截。
//
// 关键事实（写断言的依据，file:line 见下）：
// - charge 锁 user 行：Order::with(['user' => fn($q)=>$q->lockForUpdate(),...]) (ActionTrait.php:845)
// - 扣费金额取 cert.amount，transaction.amount = '-'.cert.amount（OrderUtil.php:169 负数）
// - balance_after = balance + transaction.amount（负数 → 减法）(ActionTrait.php:864)
// - 锁内校验 bccomp(balance_after, credit_limit) === -1 时非 admin 报「余额不足」(ActionTrait.php:865-867)
// - credit_limit setter 强制存为负数：abs(value)*-1（User.php:186），传 100 → 存 -100
// - 扣费成功 cert.status: unpaid → pending（ActionTrait.php:888）

/**
 * 造一个待扣费订单：unpaid 证书 + 指定 cert.amount（扣费金额取自 cert.amount）。
 * product 默认建 ProductPrice 让 charge 的 remark 组装走真实价格路径。
 */
function createUnpaidOrderForCharge(User $user, Product $product, string $amount): array
{
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'amount' => $amount,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'unpaid',
        'action' => 'new',
        'amount' => $amount,
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    $order->update(['latest_cert_id' => $cert->id]);
    $order->refresh();

    return [$order, $cert];
}

test('charge 余额刚好够：支付成功、cert 转 pending、余额归零、产生一条扣费流水', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '10.00', 'alternative_wildcard_price' => '20.00',
    ]);

    [$order, $cert] = createUnpaidOrderForCharge($user, $product, '100.00');

    // commit=false：只扣费不提交上游（对称 ACME pay($id, false)）
    expectOrderApiSuccess(fn () => $this->service->pay($order->id, false));

    expect($cert->fresh()->status)->toBe('pending');

    $user->refresh();
    expect((float) $user->balance)->toBe(0.0);

    $tx = Transaction::where('transaction_id', $order->id)->where('type', 'order')->get();
    expect($tx)->toHaveCount(1);
    expect((float) $tx->first()->amount)->toBe(-100.0);
});

test('charge 差一分钱拒绝：抛余额不足、余额不变、不产生扣费流水、cert 仍 unpaid', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '99.99']);
    $product = Product::factory()->create();

    [$order, $cert] = createUnpaidOrderForCharge($user, $product, '100.00');

    expectOrderApiError(
        fn () => $this->service->pay($order->id, false),
        '余额不足'
    );

    // 扣费被拒：状态、余额、流水均无变化
    expect($cert->fresh()->status)->toBe('unpaid');

    $user->refresh();
    expect((float) $user->balance)->toBe(99.99);

    expect(Transaction::where('transaction_id', $order->id)->count())->toBe(0);
});

test('charge 管理员授信欠费放行：admin guard 下余额为 0 仍可扣费、余额转负', function () {
    Queue::fake();

    // 设置 admin guard（charge 锁内 Auth::guard('admin')->check() 为 true 时跳过余额校验）
    $admin = Admin::factory()->create();
    $this->actingAs($admin, 'admin');
    expect(Auth::guard('admin')->check())->toBeTrue();

    $user = $this->createTestUser(['balance' => '0.00']);
    $product = Product::factory()->create();

    [$order, $cert] = createUnpaidOrderForCharge($user, $product, '100.00');

    expectOrderApiSuccess(fn () => $this->service->pay($order->id, false));

    expect($cert->fresh()->status)->toBe('pending');

    // admin 放行欠费：余额扣成负数
    $user->refresh();
    expect((float) $user->balance)->toBe(-100.0);

    $tx = Transaction::where('transaction_id', $order->id)->where('type', 'order')->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->amount)->toBe(-100.0);
});

test('charge 退款后余额回正：pay 扣费 → cancelPending 退费 → 余额恢复', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();

    [$order, $cert] = createUnpaidOrderForCharge($user, $product, '100.00');

    // 1) 扣费：balance 100 → 0，cert → pending
    expectOrderApiSuccess(fn () => $this->service->pay($order->id, false));
    $user->refresh();
    expect((float) $user->balance)->toBe(0.0);
    expect($cert->fresh()->status)->toBe('pending');

    // 2) 退费（pending + action=new 走退款分支）：balance 0 → 100，cert → cancelled
    $this->service->cancelPending($order->id);

    $user->refresh();
    expect((float) $user->balance)->toBe(100.0);
    expect($cert->fresh()->status)->toBe('cancelled');

    // 退费 cancel 交易已创建（+100），与扣费的 -100 抵消，账目恒等（FundInvariants 自动守门）
    $cancelTx = Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->first();
    expect($cancelTx)->not->toBeNull();
    expect((float) $cancelTx->amount)->toBe(100.0);
});

test('charge 同用户跨订单串行支付被 credit_limit 锁内拦截：第二笔透支被拒（最关键）', function () {
    // 复刻 ACME ActionTest「pay 串行第二次调用报错，保证只扣一次费」的串行模拟手法
    // （非真多线程，而是顺序两次调用验证锁内校验生效）。
    // 这里聚焦 charge 锁内 credit_limit 校验（ActionTrait.php:864-867）：
    // 同一用户两笔不同订单，可用额度 = balance + |credit_limit|，串行支付时第二笔
    // 累计透支超额必须被锁内重算的 balance_after < credit_limit 拦下，
    // 不能两笔都过导致用户余额突破信用额度。
    Queue::fake();

    // balance=100，credit_limit 传 100 → setter 存为 -100（允许欠费到 -100）
    // 可用额度 = 100 - (-100) = 200。两笔订单各 150：第一笔后 balance=-50（≥ -100，放行），
    // 第二笔会让 balance_after = -50 + (-150) = -200 < -100 → 拒绝。
    $user = $this->createTestUser(['balance' => '100.00', 'credit_limit' => 100]);
    expect((float) $user->credit_limit)->toBe(-100.0); // 确认 setter 语义
    $product = Product::factory()->create();

    [$orderA] = createUnpaidOrderForCharge($user, $product, '150.00');
    [$orderB] = createUnpaidOrderForCharge($user, $product, '150.00');

    // 第一笔：balance 100 → -50（仍在信用额度内），放行
    expectOrderApiSuccess(fn () => $this->service->pay($orderA->id, false));
    $user->refresh();
    expect((float) $user->balance)->toBe(-50.0);

    // 第二笔：锁内重算 balance_after = -50 + (-150) = -200 < credit_limit(-100) → 拒绝
    expectOrderApiError(
        fn () => $this->service->pay($orderB->id, false),
        '余额不足'
    );

    // 第二笔被拦：余额停在 -50（未突破 -100 信用额度），订单 B 仍 unpaid、无第二条扣费流水
    $user->refresh();
    expect((float) $user->balance)->toBe(-50.0);
    expect($orderB->latestCert->fresh()->status)->toBe('unpaid');
    expect(Transaction::where('transaction_id', $orderB->id)->count())->toBe(0);

    // 全局只有第一笔的一条扣费流水
    expect(Transaction::where('type', 'order')->where('amount', '-150.00')->count())->toBe(1);
});

test('charge 同用户串行支付恰好用满 credit_limit：两笔都在额度内则都放行', function () {
    // 对照组：证明拦截不是"第二笔一律拒"，而是精确按累计 balance_after vs credit_limit 判定。
    Queue::fake();

    // balance=100，credit_limit=-100，可用 200；两笔各 100，累计正好 -100（== credit_limit，不小于，放行）
    $user = $this->createTestUser(['balance' => '100.00', 'credit_limit' => 100]);
    $product = Product::factory()->create();

    [$orderA] = createUnpaidOrderForCharge($user, $product, '100.00');
    [$orderB] = createUnpaidOrderForCharge($user, $product, '100.00');

    expectOrderApiSuccess(fn () => $this->service->pay($orderA->id, false));
    expectOrderApiSuccess(fn () => $this->service->pay($orderB->id, false));

    $user->refresh();
    // 100 - 100 - 100 = -100，恰好用满信用额度
    expect((float) $user->balance)->toBe(-100.0);
    expect(Transaction::where('type', 'order')->count())->toBe(2);
});

// ==================== commit ====================
//
// commit() 把待提交（pending）订单提交到上游 CA。事务内调 $this->api->$action($data)，
// 上游返回 code=1 且 data.api_id 非空时写入 api_id/dcv/validation/cert_apply_status、
// 状态转 processing；否则 $this->error 触发 DB::rollback，保证"上游提交失败不污染本地
// status/api_id"（Action.php:352-429）。
//
// 上游 mock：反射注入 protected $api（与本文件 sync 终态守卫测试同范式）。Cert 工厂默认
// action='new'、status='pending'、api_id=null，正好是待 commit 的状态。

test('commit 上游 code=1：写入 api_id/dcv/validation、cert 转 processing', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction('pending', [], ['action' => 'new', 'api_id' => null]);

    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('new')
        ->once()
        ->andReturn([
            'code' => 1,
            'data' => [
                'api_id' => 'upstream-commit-001',
                'cert_apply_status' => 1,
                'dcv' => [['domain' => 'commit.example.com', 'method' => 'dns']],
                'validation' => [['domain' => 'commit.example.com', 'status' => 'pending']],
            ],
        ]);

    $ref = new ReflectionClass($this->service);
    $prop = $ref->getProperty('api');
    $prop->setAccessible(true);
    $prop->setValue($this->service, $mockApi);

    $response = expectOrderApiSuccess(fn () => $this->service->commit($order->id));

    expect($response['data']['order_id'])->toBe($order->id);
    expect($response['data']['cert_apply_status'])->toBe(1);

    $cert->refresh();
    expect($cert->status)->toBe('processing');
    expect($cert->api_id)->toBe('upstream-commit-001');
    expect($cert->cert_apply_status)->toBe(1);
    // dcv 合并后原样写入（cert.dcv 为空时取上游 apiDcv）
    expect($cert->dcv)->toBe([['domain' => 'commit.example.com', 'method' => 'dns']]);
    // validation 合并后写入：mergeValidation 给缺 method 的条目补 'admin'（ActionTrait.php:732-734）
    expect($cert->validation)->toBe([['domain' => 'commit.example.com', 'status' => 'pending', 'method' => 'admin']]);
});

test('commit 上游 code=0：回滚，cert 仍 pending、api_id 未写入', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction('pending', [], ['action' => 'new', 'api_id' => null]);

    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('new')
        ->once()
        ->andReturn([
            'code' => 0,
            'msg' => '上游提交失败',
        ]);

    $ref = new ReflectionClass($this->service);
    $prop = $ref->getProperty('api');
    $prop->setAccessible(true);
    $prop->setValue($this->service, $mockApi);

    expectOrderApiError(
        fn () => $this->service->commit($order->id),
        '上游提交失败'
    );

    // 事务回滚：状态保持 pending、api_id 未写入
    $cert->refresh();
    expect($cert->status)->toBe('pending');
    expect($cert->api_id)->toBeNull();
    expect($cert->cert_apply_status)->toBe(0);
});

test('commit 上游 code=1 但 api_id 为空：回滚，cert 仍 pending、api_id 未写入', function () {
    Queue::fake();
    [$order, $cert] = createOrderWithCertForAction('pending', [], ['action' => 'new', 'api_id' => null]);

    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('new')
        ->once()
        ->andReturn([
            'code' => 1,
            'data' => [
                // api_id 缺失：上游声称成功但未返回订单号，视为提交失败
                'cert_apply_status' => 1,
                'dcv' => [['domain' => 'commit.example.com', 'method' => 'dns']],
            ],
        ]);

    $ref = new ReflectionClass($this->service);
    $prop = $ref->getProperty('api');
    $prop->setAccessible(true);
    $prop->setValue($this->service, $mockApi);

    expectOrderApiError(
        fn () => $this->service->commit($order->id),
        '提交失败'
    );

    // 事务回滚：状态保持 pending、api_id 未写入（防上游空 api_id 污染本地）
    $cert->refresh();
    expect($cert->status)->toBe('pending');
    expect($cert->api_id)->toBeNull();
});

test('commit 非 pending 状态报错：订单状态不是待提交', function () {
    [$order, $cert] = createOrderWithCertForAction('active', [], ['action' => 'new']);

    expectOrderApiError(
        fn () => $this->service->commit($order->id),
        '订单状态不是待提交'
    );

    // 状态未变化
    expect($cert->fresh()->status)->toBe('active');
});

test('checkDuplicate 原子占位：首次放行 0，同参数重复返回剩余秒数，不同参数独立', function () {
    $method = new ReflectionMethod($this->service, 'checkDuplicate');
    $method->setAccessible(true);

    // 首次抢占成功 → 放行（0）
    expect($method->invoke($this->service, 'atomicDupTest', ['p1'], 10))->toBe(0);
    // 同参数重复 → Cache::add 失败 → 返回剩余秒数（>0 拒绝重复）
    expect($method->invoke($this->service, 'atomicDupTest', ['p1'], 10))->toBeGreaterThan(0);
    // 不同参数 → 独立 cacheKey 放行（0）
    expect($method->invoke($this->service, 'atomicDupTest', ['p2'], 10))->toBe(0);
});
