<?php

use App\Exceptions\ApiResponseException;
use App\Models\Admin;
use App\Models\Callback;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use App\Traits\RunsTaskMutationTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
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

test('commitCancel active 锁 sync/revalidate task 时强制使用复合索引', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order] = createOrderWithCertForAction('active');
    Task::factory()->create([
        'order_id' => $order->id,
        'action' => 'sync',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expectOrderApiSuccess(fn () => $this->service->commitCancel($order->id));

    $lockSql = collect($queries)->first(fn (string $sql) => str_contains($sql, 'from `tasks`')
        && str_contains($sql, 'for update'));

    expect($lockSql)->not->toBeNull();
    expect($lockSql)->toContain('force index (tasks_order_action_status_index)');
});

test('task 变更事务声明三次死锁重试', function () {
    // 重试助手已下沉为共享 trait RunsTaskMutationTransaction（Order/Acme 共用），直接对 trait 断言 attempts=3 接线
    $service = new class
    {
        use RunsTaskMutationTransaction;

        public function runForTest(Closure $callback): mixed
        {
            return $this->runTaskMutationTransaction($callback);
        }
    };

    $db = DB::getFacadeRoot();

    DB::shouldReceive('transaction')
        ->once()
        ->with(Mockery::type(Closure::class), 3)
        ->andReturnUsing(fn (Closure $callback, int $attempts) => $callback());

    try {
        $result = $service->runForTest(fn () => 'ok');
    } finally {
        DB::swap($db);
    }

    expect($result)->toBe('ok');
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

// ==================== cancelLocked reissue（B3 资金核心）====================

/**
 * 构造一个 reissue 订单处于 cancelling 态的夹具（cancelLocked 前置：status=cancelling）。
 * 旧证书 reissued、reissue cert cancelling+action=reissue+last_cert_id 指向旧证书。
 */
function makeReissueCancelling(array $certOverrides = [], array $orderOverrides = []): array
{
    $order = Order::factory()->create(array_merge([
        'user_id' => test()->user->id,
        'product_id' => test()->product->id,
        'amount' => '100.00',
        'purchased_standard_count' => 2,
        'purchased_wildcard_count' => 0,
    ], $orderOverrides));

    $oldCert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'reissued',
        'action' => 'new',
    ]);

    $reissueCert = Cert::factory()->create(array_merge([
        'order_id' => $order->id,
        'status' => 'cancelling',
        'action' => 'reissue',
        'amount' => '20.00',
        'last_cert_id' => $oldCert->id,
        'issued_at' => null,
    ], $certOverrides));

    $order->update(['latest_cert_id' => $reissueCert->id]);
    $order->refresh();

    return [$order, $oldCert, $reissueCert];
}

/**
 * 把 test()->service 的上游 api 换成 mock：cancel 成功 / 抛异常 / 断言不被调用。
 */
function mockCancelApi(string $mode = 'success'): MockInterface
{
    $mockApi = Mockery::mock(Api::class);
    if ($mode === 'success') {
        $mockApi->shouldReceive('cancel')->andReturn(['code' => 1]);
    } elseif ($mode === 'throw') {
        $mockApi->shouldReceive('cancel')->andThrow(new ApiResponseException('CA取消失败', ['reason' => 'upstream']));
    } elseif ($mode === 'never') {
        $mockApi->shouldNotReceive('cancel');
    }

    $ref = new ReflectionClass(test()->service);
    $prop = $ref->getProperty('api');
    $prop->setAccessible(true);
    $prop->setValue(test()->service, $mockApi);

    return $mockApi;
}

test('cancelLocked reissue 增域名（amount=20）：只退增量 20 非 120 + 前驱保持 reissued + latest_cert_id 不回切 + reissue cert=cancelled 不删 + last_cert_id 保留', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling();

    // 原始 new 扣费 -100 + reissue 增域名扣费 -20（若误用 getCancelTransaction 求和会退 120）
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-100.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    $balanceBefore = $this->user->fresh()->balance;

    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    // 只退增量 20（非 120）：cancel 流水 +20
    $cancelTx = Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->first();
    expect($cancelTx)->not->toBeNull();
    expect((float) $cancelTx->amount)->toBe(20.0);
    // counts 取 -last_transaction（增量非累计）
    expect($cancelTx->standard_count)->toBe(-1);

    // 余额只增 20
    expect((float) $this->user->fresh()->balance - (float) $balanceBefore)->toBe(20.0);

    // purchased_* 递减（2 - 1 = 1）
    $order->refresh();
    expect($order->purchased_standard_count)->toBe(1);

    // 订单终结（收窄后）：前驱保持 reissued（不恢复）+ latest_cert_id 保持指向 reissue cert（不回切）
    // + reissue cert 置 cancelled 不删 + last_cert_id 保留指向前驱（占槽 inert）
    expect($oldCert->fresh()->status)->toBe('reissued');
    expect($order->latest_cert_id)->toBe($reissueCert->id);
    expect($reissueCert->fresh()->status)->toBe('cancelled');
    expect(Cert::where('id', $reissueCert->id)->exists())->toBeTrue();
    expect($reissueCert->fresh()->last_cert_id)->toBe($oldCert->id);
});

test('cancelLocked reissue 未增域名（amount=0）：无 cancel 流水 + 前驱保持 reissued 不恢复不删', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling(['amount' => '0.00']);

    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    // amount=0：外层守卫整体跳过退款块，不建 cancel 流水（天然不触发 F1 唯一索引）
    expect(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(0);

    // 订单终结（收窄后）：前驱保持 reissued（不恢复）+ latest_cert_id 不回切 + reissue cert=cancelled 不删
    expect($oldCert->fresh()->status)->toBe('reissued');
    expect($order->fresh()->latest_cert_id)->toBe($reissueCert->id);
    expect($reissueCert->fresh()->status)->toBe('cancelled');
    expect(Cert::where('id', $reissueCert->id)->exists())->toBeTrue();
});

test('cancelLocked 已签发 reissue（issued_at 非 null）：退增量 + cert=cancelled + 旧证书保持 reissued + cert 不删 + cancelled_at', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling(['issued_at' => now()]);

    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    // 退增量仍发生
    $cancelTx = Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->first();
    expect((float) $cancelTx->amount)->toBe(20.0);

    // 已签发 gate：cert→cancelled、不删、cancelled_at 写入；旧证书保持 reissued（不恢复）
    $reissueCert->refresh();
    expect($reissueCert->status)->toBe('cancelled');
    expect(Cert::where('id', $reissueCert->id)->exists())->toBeTrue();
    expect($oldCert->fresh()->status)->toBe('reissued');
    expect($order->fresh()->latest_cert_id)->toBe($reissueCert->id);
    expect($order->fresh()->cancelled_at)->not->toBeNull();
});

test('cancelLocked F1：二次 reissue-cancel 撞已存在 cancel 流水 → 预检报错、api->cancel 从未被调用、无二笔退款、订单保持 cancelling', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling();

    // 已存在一条 cancel 流水（模拟首次 reissue-cancel 已退款）+ 对应 order 扣费
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'cancel', 'transaction_id' => $order->id,
        'amount' => '20.00', 'standard_count' => -1, 'wildcard_count' => 0,
    ]);

    // api->cancel 绝不能被调用（预检挡在上游调用之前）
    mockCancelApi('never');

    expectOrderApiError(
        fn () => $this->service->cancel($order->id),
        '该订单已存在取消退款流水'
    );

    // 无第二笔 cancel 流水；订单保持 cancelling（事务回滚）
    expect(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(1);
    expect($reissueCert->fresh()->status)->toBe('cancelling');
});

test('cancelLocked reissue 上游 cancel 失败 → 回滚、无退款、不置 cancelled、不恢复旧证书', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling();
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    mockCancelApi('throw');
    expectOrderApiError(fn () => $this->service->cancel($order->id), 'CA取消失败');

    // 取消不静默成功：无退款、reissue cert 仍 cancelling、旧证书仍 reissued
    expect(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(0);
    expect($reissueCert->fresh()->status)->toBe('cancelling');
    expect($oldCert->fresh()->status)->toBe('reissued');
    expect(Cert::where('id', $reissueCert->id)->exists())->toBeTrue();
});

// —— 收窄后新增：订单终结门 + last_cert_id 保留 + 未签发仍退增量 ——

test('cancelLocked reissue 取消后订单终结：再 reissue 报「订单已取消，无法重签」+ commitCancel 报「订单已取消」', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling();
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    // 先取消 reissue 接替单 → reissue cert=cancelled、latest_cert_id 仍指向它（不回切）→ 订单终结
    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));
    expect($reissueCert->fresh()->status)->toBe('cancelled');
    expect($order->fresh()->latest_cert_id)->toBe($reissueCert->id);

    // 门1：再对该订单 reissue → initParams 前置校验专属分支报错「订单已取消，无法重签」
    expectOrderApiError(
        fn () => $this->service->reissue(['action' => 'reissue', 'order_id' => $order->id]),
        '订单已取消，无法重签'
    );

    // 门2：commitCancel → latestCert=cancelled 报「订单已取消」
    expectOrderApiError(
        fn () => $this->service->commitCancel($order->id),
        '订单已取消'
    );

    // 订单终结不产生额外资金流水：仍只有首次的 1 笔 cancel（+20）
    expect(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(1);
});

test('cancelLocked reissue 取消后 last_cert_id 保留：cancelled reissue cert 仍指向前驱（占槽 inert 不置 null）', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling();
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    // 与 cancelPending renew 释放槽位（last_cert_id=null）相反：reissue 收窄保留 last_cert_id，
    // 维持「cancelled 接替 → reissued 前驱」取证链；订单终结后该 UNIQUE 槽位对前驱 inert。
    $reissueCert->refresh();
    expect($reissueCert->status)->toBe('cancelled');
    expect($reissueCert->last_cert_id)->toBe($oldCert->id);
    expect($reissueCert->last_cert_id)->not->toBeNull();
});

test('cancelLocked reissue 未签发（issued_at=null，processing 语义）取消：仍退增量（cancel 流水 +20）+ cert=cancelled 不删', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    // makeReissueCancelling 默认 issued_at=null（processing/approving 语义，未签发）
    [$order, $oldCert, $reissueCert] = makeReissueCancelling();
    expect($reissueCert->issued_at)->toBeNull();

    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    // 收窄前未签发走恢复分支不退增量地删 cert；收窄后与 F3 合流，未签发同样退增量口径
    $cancelTx = Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->first();
    expect($cancelTx)->not->toBeNull();
    expect((float) $cancelTx->amount)->toBe(20.0);
    expect($cancelTx->standard_count)->toBe(-1);

    // cert=cancelled、不删、cancelled_at 写入
    expect($reissueCert->fresh()->status)->toBe('cancelled');
    expect(Cert::where('id', $reissueCert->id)->exists())->toBeTrue();
    expect($order->fresh()->cancelled_at)->not->toBeNull();
});

test('cancelLocked new/renew 回归：仍走 getCancelTransaction 求和单笔口径（reissue 分支零影响）', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $cert] = createOrderWithCertForAction(
        'cancelling',
        ['amount' => '100.00'],
        ['action' => 'new', 'amount' => '100.00'],
    );

    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-100.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    // new 单笔：getCancelTransaction 求和 = order.amount = 100，退 100（口径正确）
    $cancelTx = Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->first();
    expect((float) $cancelTx->amount)->toBe(100.0);
    expect($cert->fresh()->status)->toBe('cancelled');
    expect($order->fresh()->cancelled_at)->not->toBeNull();
});

// ==================== 任务3：接替单取消一次性通知 cert_renew_cancelled ====================

/**
 * 装一个「捕获式」NotificationCenter mock，收集全部派发的 intent（对象句柄，命令执行后即含全部 intent）。
 * cancelLocked 内经 app(NotificationCenter::class) 解析，故 app()->instance 绑定即命中。
 */
function captureRenewCancelledDispatch(): ArrayObject
{
    $captured = new ArrayObject;
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($captured) {
        $captured->append($intent);
    });
    app()->instance(NotificationCenter::class, $mock);

    return $captured;
}

/** 从捕获集合中筛出指定 code 的 intent（保序）。 */
function renewCancelledIntents(ArrayObject $captured, string $code = 'cert_renew_cancelled'): array
{
    $out = [];
    foreach ($captured as $intent) {
        if ($intent->code === $code) {
            $out[] = $intent;
        }
    }

    return $out;
}

/**
 * 构造一个 renew 接替单处于 cancelling 态的夹具（cancelLocked 前置：status=cancelling）。
 * 源证书 renewed（另开订单，带 common_name/expires_at）、renew cert cancelling+action=renew+last_cert_id 指向源证书。
 */
function makeRenewCancelling(array $certOverrides = []): array
{
    $sourceOrder = Order::factory()->create([
        'user_id' => test()->user->id,
        'product_id' => test()->product->id,
    ]);
    $sourceCert = Cert::factory()->create([
        'order_id' => $sourceOrder->id,
        'status' => 'renewed',
        'action' => 'new',
        'common_name' => 'pred-renew.example.com',
        'expires_at' => now()->addDays(60),
    ]);
    $sourceOrder->update(['latest_cert_id' => $sourceCert->id]);

    $renewOrder = Order::factory()->create([
        'user_id' => test()->user->id,
        'product_id' => test()->product->id,
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $renewCert = Cert::factory()->create(array_merge([
        'order_id' => $renewOrder->id,
        'status' => 'cancelling',
        'action' => 'renew',
        'amount' => '100.00',
        'last_cert_id' => $sourceCert->id,
        'issued_at' => null,
    ], $certOverrides));
    $renewOrder->update(['latest_cert_id' => $renewCert->id]);
    $renewOrder->refresh();

    return [$renewOrder, $sourceCert, $renewCert];
}

test('[通知①] reissue processing（issued_at=null）取消 → 派发恰一次 cert_renew_cancelled + context 白名单键齐、无外键', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$order, $oldCert, $reissueCert] = makeReissueCancelling();
    // 前驱域名/到期日显式钉死，供断言 context 取值来自前驱
    $oldCert->update(['common_name' => 'pred-reissue.example.com', 'expires_at' => now()->addDays(90)]);
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    $captured = captureRenewCancelledDispatch();
    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    $intents = renewCancelledIntents($captured);
    expect($intents)->toHaveCount(1);

    $intent = $intents[0];
    expect($intent->code)->toBe('cert_renew_cancelled')
        ->and($intent->notifiableType)->toBe('user')
        ->and($intent->notifiableId)->toBe($this->user->id);

    // context 严格白名单：恰 4 键，无 toArray 整包泄漏（无 email/user_id/csr/private_key 等外键）
    expect(array_keys($intent->context))->toEqualCanonicalizing(['common_name', 'expires_at', 'order_id', 'action']);
    expect($intent->context['common_name'])->toBe('pred-reissue.example.com')
        ->and($intent->context['expires_at'])->toBe(now()->addDays(90)->format('Y-m-d'))
        ->and($intent->context['order_id'])->toBe($order->id)
        ->and($intent->context['action'])->toBe('重签');
});

test('[通知②] renew cancelLocked（有前驱）取消 → 对称派发一次 cert_renew_cancelled + action=续费', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    [$renewOrder, $sourceCert, $renewCert] = makeRenewCancelling();
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $renewOrder->id,
        'amount' => '-100.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    $captured = captureRenewCancelledDispatch();
    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($renewOrder->id));

    // renew 走 getCancelTransaction 单笔口径取消成功
    expect($renewCert->fresh()->status)->toBe('cancelled');

    $intents = renewCancelledIntents($captured);
    expect($intents)->toHaveCount(1);
    expect($intents[0]->notifiableId)->toBe($this->user->id);
    expect(array_keys($intents[0]->context))->toEqualCanonicalizing(['common_name', 'expires_at', 'order_id', 'action']);
    expect($intents[0]->context['common_name'])->toBe('pred-renew.example.com')
        ->and($intents[0]->context['action'])->toBe('续费')
        ->and($intents[0]->context['order_id'])->toBe($renewOrder->id);
});

test('[通知③] plain new（last_cert_id=null，无前驱）取消 → 不派发 cert_renew_cancelled', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    // action=new + last_cert_id=null（工厂默认）：无前驱接替单
    [$order, $cert] = createOrderWithCertForAction(
        'cancelling',
        ['amount' => '100.00'],
        ['action' => 'new', 'amount' => '100.00'],
    );
    expect($cert->last_cert_id)->toBeNull();
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-100.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    $captured = captureRenewCancelledDispatch();
    mockCancelApi('success');
    expectOrderApiSuccess(fn () => $this->service->cancel($order->id));

    expect($cert->fresh()->status)->toBe('cancelled');
    expect(renewCancelledIntents($captured))->toBeEmpty();
});

test('[通知④] pending 取消（cancelPending 恢复路径）→ 不派发 cert_renew_cancelled（恢复语义不收口）', function () {
    Queue::fake();

    // pending reissue 接替单（恢复窗口内）：cancelPending 会恢复前驱，不发一次性取消通知
    [$order, $oldCert, $reissueCert] = makeReissueCancelling(['status' => 'pending']);
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);

    $captured = captureRenewCancelledDispatch();
    $this->service->cancelPending($order->id);

    // 恢复路径已执行：前驱恢复 active、reissue cert 被删（restoreReissuedCert）——恢复语义不发一次性取消通知
    expect($oldCert->fresh()->status)->toBe('active');
    expect(Cert::where('id', $reissueCert->id)->exists())->toBeFalse();
    expect(renewCancelledIntents($captured))->toBeEmpty();
});

test('cancelPending reissue F1：二次取消预检报错（共享 helper 生效）', function () {
    Queue::fake();

    // pending reissue 订单 + 已存在 cancel 流水（模拟首次已退款）
    [$order, $oldCert, $reissueCert] = makeReissueCancelling(['status' => 'pending']);
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'order', 'transaction_id' => $order->id,
        'amount' => '-20.00', 'standard_count' => 1, 'wildcard_count' => 0,
    ]);
    Transaction::create([
        'user_id' => $this->user->id, 'type' => 'cancel', 'transaction_id' => $order->id,
        'amount' => '20.00', 'standard_count' => -1, 'wildcard_count' => 0,
    ]);

    // 共享 prepareReissueRefund 的 F1 预检在 cancelPending 也生效（pending 无上游调用，预检失败仅本地回滚）
    expectOrderApiError(
        fn () => $this->service->cancelPending($order->id),
        '该订单已存在取消退款流水'
    );

    expect(Transaction::where('transaction_id', $order->id)->where('type', 'cancel')->count())->toBe(1);
    expect($reissueCert->fresh()->status)->toBe('pending');
});

test('代理前提固化：processing reissue cert issued_at=null，commitCancel→cancelling 后仍 null（issued_at 闸门依据）', function () {
    Queue::fake();
    test()->product->update(['refund_period' => 30]);

    // processing reissue cert：未签发恒 issued_at=null
    [$order, $oldCert, $reissueCert] = makeReissueCancelling(['status' => 'processing', 'issued_at' => null]);
    expect($reissueCert->issued_at)->toBeNull();

    // commitCancel 置 cancelling（只改 status，不动 issued_at）
    expectOrderApiSuccess(fn () => $this->service->commitCancel($order->id));

    $reissueCert->refresh();
    expect($reissueCert->status)->toBe('cancelling');
    // 闸门代理前提：cancelling 迁移保留 issued_at=null，故 cancelLocked 能用它区分"从未 active"
    expect($reissueCert->issued_at)->toBeNull();
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

// ==================== sync 回调抑制（下游 pull 已同步返回，避免冗余回调）====================
//
// V1/V2 ApiController::get 内走 sync($id, force=true, suppressCallback=true)：下游主动 pull 时
// get 已把新状态同步返回，无需再异步回调下游（多一次触发）。后台 TaskJob/手动 sync 不传，回调照常。

function mockSyncReturnsActive(): void
{
    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('get')->andReturn([
        'code' => 1,
        'data' => ['status' => 'active'],
    ]);
    $ref = new ReflectionClass(test()->service);
    $prop = $ref->getProperty('api');
    $prop->setAccessible(true);
    $prop->setValue(test()->service, $mockApi);
}

test('sync 默认（后台/手动）状态变 active 且启用回调时创建 callback 任务', function () {
    Queue::fake();
    [$order] = createOrderWithCertForRevoke('processing');
    Callback::create(['user_id' => $this->user->id, 'url' => 'https://d.example.com/cb', 'token' => 't', 'status' => 1]);
    mockSyncReturnsActive();

    // force=true：sync 末尾 $force || success() 短路、不抛异常，直接调用
    $this->service->sync($order->id, true, false);

    expect(Task::where('order_id', $order->id)->where('action', 'callback')->count())->toBe(1);
});

test('sync 下游 pull（suppressCallback=true）状态变 active 不创建 callback 任务', function () {
    Queue::fake();
    [$order] = createOrderWithCertForRevoke('processing');
    Callback::create(['user_id' => $this->user->id, 'url' => 'https://d.example.com/cb', 'token' => 't', 'status' => 1]);
    mockSyncReturnsActive();

    $this->service->sync($order->id, true, true);

    expect(Task::where('order_id', $order->id)->where('action', 'callback')->count())->toBe(0);
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

// ==================== importProduct weight 保护 ====================

/**
 * 构造上游产品 item，注入 Order\Api mock，执行 importProduct(update 分支)
 */
function runImportProductUpdate(Action $service, Product $existing, array $upstreamItem): void
{
    $upstreamItem['code'] = $existing->api_id;

    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('getProducts')
        ->andReturn([
            'code' => 1,
            'data' => [$upstreamItem],
        ]);

    $ref = new ReflectionClass($service);
    $prop = $ref->getProperty('api');
    $prop->setAccessible(true);
    $prop->setValue($service, $mockApi);

    try {
        $service->importProduct('default', '', '', 'update');
    } catch (ApiResponseException $e) {
        // importProduct 末尾 success() 会抛，正常
    }
}

test('importProduct update：本地 weight 非 0 时上游 weight 不覆盖', function () {
    $product = Product::factory()->create([
        'source' => 'default',
        'weight' => 5,
    ]);

    runImportProductUpdate($this->service, $product, ['weight' => 10]);

    expect($product->fresh()->weight)->toBe(5);
});

test('importProduct update：本地 weight 为 0（默认）时上游 weight 可写入', function () {
    $product = Product::factory()->create([
        'source' => 'default',
        'weight' => 0,
    ]);

    runImportProductUpdate($this->service, $product, ['weight' => 10]);

    expect($product->fresh()->weight)->toBe(10);
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

// ==================== B 锁下沉：new(renew) / reissue 守卫（任务2）====================

/**
 * 造一个 active 已签发、可续费/重签的源订单（B 锁下沉守卫测试用）。
 * product renew=1/reissue=1/status=1/reuse_csr=0（走 csr_generate 本地生成、无上游），价格已配；
 * period_till 在 30 天内（过 initParams renew 前置门）、未过期（过 reissue 前置门）。
 *
 * @return array{0: Order, 1: Cert, 2: Product}
 */
function makeBLockSourceOrder(array $certOverrides = []): array
{
    $user = test()->user;
    $product = Product::factory()->create([
        'status' => 1, 'renew' => 1, 'reissue' => 1, 'reuse_csr' => 0,
        'product_type' => Product::TYPE_SSL, 'validation_type' => 'dv',
        'validation_methods' => ['txt', 'file', 'cname'],
        'common_name_types' => ['standard'], 'alternative_name_types' => ['standard'],
        'periods' => [12], 'standard_min' => 1, 'standard_max' => 5,
        'wildcard_min' => 0, 'wildcard_max' => 5, 'total_min' => 1, 'total_max' => 10,
        'refund_period' => 30,
    ]);
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => $user->level_code, 'period' => 12,
        'price' => '100.00', 'alternative_standard_price' => '10.00', 'alternative_wildcard_price' => '20.00',
    ]);

    $domain = 'b-lock-'.uniqid().'.example.com';
    $order = Order::factory()->create([
        'user_id' => $user->id, 'product_id' => $product->id,
        'period' => 12, 'period_from' => now()->subMonths(11), 'period_till' => now()->addDays(10),
        'purchased_standard_count' => 1, 'purchased_wildcard_count' => 0, 'amount' => '100.00',
    ]);
    $cert = Cert::factory()->create(array_merge([
        'order_id' => $order->id, 'status' => 'active', 'action' => 'new',
        'common_name' => $domain, 'alternative_names' => $domain,
        'standard_count' => 1, 'wildcard_count' => 0,
        'encryption_alg' => 'RSA', 'encryption_bits' => 2048, 'signature_digest_alg' => 'SHA256',
    ], $certOverrides));
    $order->update(['latest_cert_id' => $cert->id]);

    return [$order, $cert, $product];
}

/** 续费/重签入参（domains 取自源证书、csr_generate 本地生成，与 AutoRenewCommand 对齐）。 */
function bLockParams(Order $order, Cert $cert, string $action): array
{
    return [
        'action' => $action,
        'channel' => 'web',
        'order_id' => $order->id,
        'validation_method' => 'txt',
        'domains' => $cert->alternative_names,
        'csr_generate' => 1,
        'period' => 12,
    ];
}

test('new(renew) affected-rows 守卫：源证书被并发翻走 → 订单已续费 + 回滚', function () {
    Queue::fake();
    [$sourceOrder, $sourceCert] = makeBLockSourceOrder();

    // 同连接注入：接替单 Order::create 触发 Order::created 时 raw 把源证书翻 renewed（绕 Eloquent、同事务可见），
    // 守卫 WHERE status='active' 随即命中 0 行（仿 AutoRenewAtomicityTest::depleteBalanceOnFirstCert 范式）。
    $injected = false;
    Order::created(function (Order $o) use ($sourceCert, &$injected) {
        if ($injected) {
            return;
        }
        $injected = true;
        DB::table('certs')->where('id', $sourceCert->id)->update(['status' => 'renewed']);
    });

    try {
        expectOrderApiError(
            fn () => $this->service->new(bLockParams($sourceOrder, $sourceCert, 'renew')),
            '订单已续费'
        );
    } finally {
        // 精准清理本用例注册的 Order::created（snowflake 是 creating、不受影响；Order 无其他 created 监听）
        Order::getEventDispatcher()->forget('eloquent.created: '.Order::class);
    }

    // 回滚完备：接替单未落库（源订单外无新单）、无扣费流水、注入随 savepoint 回滚 → 源证书复原 active。
    // 错误消息 '订单已续费' 证明守卫 0 行后重读到注入的 renewed（回滚前同连接可见）。
    expect(Order::where('user_id', $this->user->id)->where('id', '!=', $sourceOrder->id)->count())->toBe(0);
    expect(Transaction::count())->toBe(0);
    expect($sourceCert->fresh()->status)->toBe('active');
});

test('new(renew) affected-rows=0 三态消息：注入 renewed/reissued/cancelled 分别报对应错误', function () {
    Queue::fake();

    $cases = [
        'renewed' => '订单已续费',
        'reissued' => '订单已重签',
        'cancelled' => '订单已取消',
    ];

    foreach ($cases as $injectStatus => $expectedMsg) {
        // 每态一份新 fixture：独立 order → 独立 checkDuplicate 键、独立监听。禁用预置前驱终态构造
        // （会被 initParams 前置门拦成伪绿），一律靠 Order::created 事务内注入过前置门后再触守卫。
        [$sourceOrder, $sourceCert] = makeBLockSourceOrder();

        $injected = false;
        Order::created(function (Order $o) use ($sourceCert, $injectStatus, &$injected) {
            if ($injected) {
                return;
            }
            $injected = true;
            DB::table('certs')->where('id', $sourceCert->id)->update(['status' => $injectStatus]);
        });

        try {
            expectOrderApiError(
                fn () => $this->service->new(bLockParams($sourceOrder, $sourceCert, 'renew')),
                $expectedMsg
            );
        } finally {
            Order::getEventDispatcher()->forget('eloquent.created: '.Order::class);
        }
    }
});

test('reissue 双开物理底线：last_cert_id UNIQUE 槽位被占死 → Cert::create 撞 1062 回滚', function () {
    Queue::fake();
    [$order, $prevCert] = makeBLockSourceOrder();

    // 预建孤儿 cert 占死 last_cert_id=前驱 的 UNIQUE 槽位（order.latest_cert_id 仍指 active 的前驱，过 initParams）
    Cert::factory()->create([
        'order_id' => null,
        'status' => 'reissued',
        'action' => 'reissue',
        'last_cert_id' => $prevCert->id,
    ]);

    // reissue 前驱翻转（affected=1）后 Cert::create(last_cert_id=前驱) → 撞 certs.last_cert_id UNIQUE → QueryException 回滚
    expect(fn () => $this->service->reissue(bLockParams($order, $prevCert, 'reissue')))
        ->toThrow(QueryException::class);

    // 无新 cert 落库（order 上仅前驱一条）、前驱随回滚复原 active
    expect(Cert::where('order_id', $order->id)->count())->toBe(1);
    expect($prevCert->fresh()->status)->toBe('active');
});

// 注：reissue 的 re-read 守卫（latest_cert_id 锁内重读 != 基线 → 订单已重签）无干净同连接 seam——
// initParams 内 filterParamsField→getProductType 有一次无锁 Order::find（首个 plain orders 查询），
// DB::listen 会在 last_cert_id 捕获前命中它使基线同步推进、注入空过（计划 RECHECK R-3 明示）。
// 计数命中第二个查询的构造过于脆弱（随查询序漂移），故弃 ②b；该守卫是 UNIQUE 物理底线（②）之上的
// 纵深防御，双开物理阻断由 ② 覆盖。

test('new/reissue checkDuplicate 保留：同参 10s 内二次提交报参数重复', function () {
    Queue::fake();
    [$order, $cert] = makeBLockSourceOrder();
    $params = bLockParams($order, $cert, 'reissue');

    // 第一次调用由 checkDuplicate（reissue 首行）抢占缓存键（成功抛 code=1 success，吞掉）
    try {
        $this->service->reissue($params);
    } catch (ApiResponseException) {
        // 忽略首次结果，仅需其占位缓存键
    }

    // 第二次同参：checkDuplicate 拦截
    expectOrderApiError(fn () => $this->service->reissue($params), '参数重复');
});
