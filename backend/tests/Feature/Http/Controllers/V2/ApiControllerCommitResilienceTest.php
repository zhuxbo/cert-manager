<?php

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
use App\Http\Controllers\V2\ApiController;
use App\Models\ApiToken;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Traits\CreatesTestData;

/**
 * M1 —— V2 一条龙（new/renew/reissue）拆出上游 commit 调用的端到端回归。
 *
 * 事故背景：new/renew/reissue 原把「建单 + 扣费 + commit(调上游)」裹进单个外层事务，
 * commit 调上游超时抛 code=0 冒泡触发整笔 rollback，连已扣费一起回滚 →「上游有单、manager 零记录」。
 *
 * M1 目标：外层事务只包 new + pay(commit=false)（建单 + 扣费落 pending，原子），
 * commit 移到事务外独立调用；commit 失败/超时/抢锁忙不回滚已扣费，订单停 pending（api_id=NULL），
 * 返回下游既有 processing 展示态，靠对账/pull 自愈。
 *
 * 范式沿用同目录 ApiControllerFundFlowTest：全程走 routes/api.v2.php 真实路由 + 真实加密 token +
 * ApiAuthenticate（注册 UserScope），Action 用容器真身，仅把上游网络边界 Order\Api\Api 换成桩。
 * 关键：Action::new/reissue 建单阶段不调 $this->api（已核实 Action.php:184-321），
 * 桩的 ->new()/->renew()/->reissue() 只在 commit 阶段被触发 —— 让其返回 code=0 即精确模拟
 * 「commit 超时」而不影响建单 + 扣费。
 */
uses(CreatesTestData::class);

beforeEach(function () {
    Cache::flush();
});

// ── 辅助函数 ──

function commitResAuthHeaders(User $user): array
{
    $plainToken = ApiToken::createToken($user->id);

    return ['Authorization' => "Bearer $plainToken"];
}

function commitResPost(User $user, string $uri, array $data = []): TestResponse
{
    return test()->withHeaders(commitResAuthHeaders($user))->postJson($uri, $data);
}

/**
 * 标准可下单 dv SSL 产品 + 会员价，SAN 价置 0 使金额 = 基础价（断言清晰）。
 */
function commitResProduct(string $price = '100.00'): Product
{
    $product = Product::factory()->create([
        'status' => 1,
        'product_type' => Product::TYPE_SSL,
        'validation_type' => 'dv',
        'validation_methods' => ['txt'],
        'common_name_types' => ['standard'],
        'alternative_name_types' => ['standard'],
        'periods' => [12],
        'gift_root_domain' => 0,
        'total_min' => 1,
        'total_max' => 1,
        'standard_min' => 0,
        'standard_max' => 1,
        'wildcard_min' => 0,
        'wildcard_max' => 0,
        'refund_period' => 30,
    ]);

    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => 'standard',
        'period' => 12,
        'price' => $price,
        'alternative_standard_price' => '0.00',
        'alternative_wildcard_price' => '0.00',
    ]);

    return $product;
}

/**
 * 上游桩：commit 成功返回 code=1 + api_id，本地据此置 processing。
 * commit 阶段按 cert.action 调上游（new→->new / renew→->renew / reissue→->reissue），三者都桩成功。
 */
function bindCommitOkStub(): MockInterface
{
    $ok = ['code' => 1, 'data' => ['api_id' => 'stub-api-id', 'cert_apply_status' => 0]];
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('new')->andReturn($ok);
    $mock->shouldReceive('renew')->andReturn($ok);
    $mock->shouldReceive('reissue')->andReturn($ok);
    app()->instance(Api::class, $mock);

    return $mock;
}

/**
 * 上游桩：commit 阶段调 ->new() 返回 code=0（模拟 SDK 把超时压成 code=0），
 * msg 故意含内部地址，验证不外泄（M2 脱敏在 SDK 层，此处验证 M1 不把该 msg 冒泡给下游）。
 */
function bindCommitTimeoutStub(): MockInterface
{
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('new')->andReturn([
        'code' => 0,
        'msg' => 'Request failed: cURL error 28: Operation timed out https://upstream.test/api/v2/new',
    ]);
    app()->instance(Api::class, $mock);

    return $mock;
}

// ====================================================================
// new 成功：new(unpaid) → pay(扣费/pending) → commit(processing/api_id 落库)
// ====================================================================

test('new 成功：commit 落库 api_id + processing，响应含 order_id', function () {
    bindCommitOkStub();

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');

    $response = commitResPost($user, '/api/v2/new', [
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'commit-ok.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $orderId = $response->json('data.order_id');
    expect($orderId)->not->toBeNull();

    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert->status)->toBe('processing');
    expect($cert->api_id)->toBe('stub-api-id');

    // 扣费落库：余额 200 → 100，恰一条 -100 order 流水
    expect($user->refresh()->balance)->toBe('100.00');
    $tx = Transaction::withoutGlobalScopes()
        ->where('type', 'order')->where('transaction_id', $orderId)->get();
    expect($tx)->toHaveCount(1);
    expect((float) $tx->first()->amount)->toBe(-100.0);
});

// ====================================================================
// new commit 超时（核心）：不回滚、保 pending + 已扣费、api_id=NULL、200 展示 processing、msg 不泄露
// ====================================================================

test('new commit 超时（SDK code=0）：订单不回滚、cert 停 pending、api_id 空、扣费保留、200 展示 processing、msg 不泄露内部地址', function () {
    bindCommitTimeoutStub();

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');

    $response = commitResPost($user, '/api/v2/new', [
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'commit-timeout.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    // ⑤ HTTP 200 + code=1（成功态，不因 commit 超时报错）
    $response->assertOk()->assertJson(['code' => 1]);
    $orderId = $response->json('data.order_id');
    expect($orderId)->not->toBeNull();

    // ① 订单未回滚：new + pay 已落库
    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order)->not->toBeNull();
    expect($order->user_id)->toBe($user->id);

    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert)->not->toBeNull();
    // ② cert.status = pending（非 processing —— DB 停 pending）
    expect($cert->status)->toBe('pending');
    // ③ api_id = NULL（commit 未成功落库）
    expect($cert->api_id)->toBeNull();

    // ④ 扣费仍在：balance 已扣、type=order 流水存在
    expect($user->refresh()->balance)->toBe('100.00');
    $tx = Transaction::withoutGlobalScopes()
        ->where('type', 'order')->where('transaction_id', $orderId)->get();
    expect($tx)->toHaveCount(1);
    expect((float) $tx->first()->amount)->toBe(-100.0);

    // ⑥ 响应不泄露内部地址 / Guzzle 原文（M1 不把 commit code=0 的 msg 冒泡给下游）
    $body = $response->getContent();
    expect($body)->not->toContain('upstream.test');
    expect($body)->not->toContain('https://');
    expect($body)->not->toContain('Request failed');
    expect($body)->not->toContain('cURL');

    // 未建 commit task（pay commit=false 不建；杀手场景：靠对账 M4 兜底扫 pending）
    expect(Task::withoutGlobalScopes()
        ->where('order_id', $orderId)->where('action', 'commit')->count())->toBe(0);
});

test('get 命中 pending 且 commit 抢锁忙：不冒 503，返回 processing 展示态', function () {
    $user = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'pending',
        'api_id' => null,
        'common_name' => 'get-busy-v2.example.com',
        'alternative_names' => 'get-busy-v2.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->once()->with($order->id)->andThrow(
        new MutationBusyException("order_mutate_{$order->id}")
    );
    app()->instance(Action::class, $action);

    $response = $this->withHeaders(commitResAuthHeaders($user))
        ->getJson('/api/v2/get?order_id='.$order->id);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.status'))->toBe('processing');
    expect($cert->fresh()->status)->toBe('pending');
});

// ====================================================================
// M5：refer_id 命中 pending 卡单时幂等重提 commit
// ====================================================================

test('同 refer_id 重试命中 pending 卡单：不硬拒，重提 commit 后返回同一 order_id', function () {
    $ok = ['code' => 1, 'data' => ['api_id' => 'stub-api-id', 'cert_apply_status' => 0]];
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('new')->twice()->andReturn(
        [
            'code' => 0,
            'msg' => 'Request failed: cURL error 28: Operation timed out https://upstream.test/api/v2/new',
        ],
        $ok,
    );
    $mock->shouldReceive('renew')->andReturn($ok);
    $mock->shouldReceive('reissue')->andReturn($ok);
    app()->instance(Api::class, $mock);

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');
    $referId = 'm5pendingretryv20000000000000000';

    $first = commitResPost($user, '/api/v2/new', [
        'refer_id' => $referId,
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'm5-pending-v2.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $first->assertOk()->assertJson(['code' => 1]);
    $orderId = $first->json('data.order_id');
    expect(Cert::withoutGlobalScopes()->where('order_id', $orderId)->value('status'))->toBe('pending');

    $second = commitResPost($user, '/api/v2/new', [
        'refer_id' => $referId,
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'm5-pending-v2.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $second->assertOk()->assertJson(['code' => 1]);
    expect($second->json('data.order_id'))->toBe($orderId);

    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert->status)->toBe('processing');
    expect($cert->api_id)->toBe('stub-api-id');
    expect(Order::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1);
});

test('同 refer_id 重试命中已提交订单：直接幂等返回，不再调用上游 commit', function () {
    bindCommitOkStub();

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');
    $referId = 'm5existingv200000000000000000000';

    $first = commitResPost($user, '/api/v2/new', [
        'refer_id' => $referId,
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'm5-existing-v2.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $first->assertOk()->assertJson(['code' => 1]);
    $orderId = $first->json('data.order_id');

    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('new')->never();
    $mock->shouldReceive('renew')->never();
    $mock->shouldReceive('reissue')->never();
    app()->instance(Api::class, $mock);

    $second = commitResPost($user, '/api/v2/new', [
        'refer_id' => $referId,
        'product_code' => 'missing-product-code',
        'period' => 12,
        'domains' => 'ignored.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $second->assertOk()->assertJson(['code' => 1]);
    expect($second->json('data.order_id'))->toBe($orderId);
    expect(Order::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1);
});

// 守卫回归（钉死）：resolveReferId 的重提 commit 分支条件是 `! api_id && status === 'pending'`，
// 终态订单（cancelled/revoked/renewed 等，api_id 可能仍空）绝不能被"重提 commit"复活。
test('同 refer_id 命中终态订单（cancelled/api_id 空）：不重提上游 commit、不复活，幂等返回既有 order_id', function () {
    // 直接断言控制器层守卫：resolveReferId 对终态订单绝不调 Action::commit —— 唯一钉死控制器守卫本身。
    // 不用 Api::class ->never()：Action::commitLocked 有第二道 status!=pending 兜底，即便削弱控制器守卫
    // 也不会真触达上游 Api，Api 层断言无法区分控制器守卫是否生效（round-2 L-1 教训）。
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->never();
    app()->instance(Action::class, $action);

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');
    $referId = 'm5terminalv200000000000000000000';

    // 直接造一个终态（cancelled）+ api_id=NULL 的订单，挂上 refer_id
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'cancelled',
        'api_id' => null,
        'refer_id' => $referId,
        'common_name' => 'm5-terminal-v2.example.com',
        'alternative_names' => 'm5-terminal-v2.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $response = commitResPost($user, '/api/v2/new', [
        'refer_id' => $referId,
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'ignored.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    // 幂等返回既有订单（不硬拒、不新建）
    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.order_id'))->toBe($order->id);

    // 未复活：仍 cancelled、api_id 仍空、未新增订单
    expect($cert->fresh()->status)->toBe('cancelled');
    expect($cert->fresh()->api_id)->toBeNull();
    expect(Order::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1);
});

// ====================================================================
// getData 分流单测（定向，不走真实路由）：commit 段吞 code=0 与 MutationBusyException，其它段仍冒泡
// ====================================================================
// 端到端模拟 order_mutate 抢锁忙需 mock Cache::lock，会牵连 Action 内其它 Cache 依赖（checkDuplicate 等）而脆弱；
// 改用反射直调 private getData(action, params) + mock Action 的对应方法抛异常，精确验证 getData 的分流骨架。

/**
 * 反射构造 V2 ApiController（绕过构造函数），注入 mock Action，反射调 private getData。
 */
function callV2GetData(Action $action, string $act, array $params): array
{
    $controller = (new ReflectionClass(ApiController::class))
        ->newInstanceWithoutConstructor();
    $prop = (new ReflectionClass($controller))->getProperty('action');
    $prop->setValue($controller, $action);
    $prop2 = (new ReflectionClass($controller))->getProperty('user_id');
    $prop2->setValue($controller, 1);

    $m = (new ReflectionClass($controller))->getMethod('getData');

    return $m->invoke($controller, $act, $params);
}

test('getData: commit 段吞 SDK code=0（不冒泡），返回 []', function () {
    $action = Mockery::mock(Action::class);
    // ApiResponseException 构造：($msg, $errors, $data, $code)
    $action->shouldReceive('commit')->once()->andThrow(
        new ApiResponseException(
            'Request failed: cURL error 28 https://upstream.test/x', null, null, 0
        )
    );

    // 不抛异常、返回 []
    $result = callV2GetData($action, 'commit', [123]);
    expect($result)->toBe([]);
});

test('getData: commit 段吞 MutationBusyException（不抛 503），返回 []', function () {
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->once()->andThrow(
        new MutationBusyException('order_mutate_123')
    );

    $result = callV2GetData($action, 'commit', [123]);
    expect($result)->toBe([]);
});

test('getData: commit 段 code=1（success 抛出）透传 result（api_id 落库场景不吞）', function () {
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->once()->andThrow(
        new ApiResponseException('', null, ['order_id' => 123, 'cert_apply_status' => 0], 1)
    );

    $result = callV2GetData($action, 'commit', [123]);
    expect($result['code'])->toBe(1);
    expect($result['data']['order_id'])->toBe(123);
});

test('getData: new 段 code=0 仍冒泡（$this->error 抛 ApiResponseException 终止请求）', function () {
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('new')->once()->andThrow(
        new ApiResponseException('余额不足', null, null, 0)
    );

    // new 段 code=0 → $this->error → 抛 ApiResponseException（不被吞）
    expect(fn () => callV2GetData($action, 'new', [['x' => 1]]))
        ->toThrow(ApiResponseException::class);
});

test('getData: pay 段 MutationBusyException 仍向上抛（非 commit 段不吞忙）', function () {
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('pay')->once()->andThrow(
        new MutationBusyException('order_mutate_123')
    );

    expect(fn () => callV2GetData($action, 'pay', [123, false, false]))
        ->toThrow(MutationBusyException::class);
});

// ====================================================================
// 进程崩溃模拟（杀手 1）：只 new+pay 不 commit → pending / api_id=NULL / 扣费在
// ====================================================================
// 证明「charge 提交后、commit 前进程崩溃」时 new+pay 已落库不回滚，M4 需扫 pending 兜底。
// 用反射构造控制器手动只跑到 pay（不调 commit），模拟崩溃在 getData('commit') 前。

test('进程崩溃模拟：new+pay 后未 commit，订单停 pending、api_id 空、扣费已落库', function () {
    bindCommitOkStub(); // 桩存在但本用例不触发 commit

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');

    $action = app(Action::class);

    $params = [
        'product_id' => $product->id,
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'crash-before-commit.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
        'user_id' => $user->id,
        'action' => 'new',
        'channel' => 'api',
    ];

    // 模拟控制器外层事务：new + pay(commit=false)，然后「崩溃」（不调 commit）
    $orderId = null;
    DB::transaction(function () use ($action, $params, &$orderId) {
        try {
            $action->new($params);
        } catch (ApiResponseException $e) {
            // Action::new 成功也抛 ApiResponseException(code=1)，取 order_id
            $orderId = $e->getApiResponse()['data']['order_id'] ?? null;
        }
        try {
            $action->pay($orderId, false, false);
        } catch (ApiResponseException) {
            // pay 成功抛 code=1
        }
        // ← 此处「崩溃」：不调 $action->commit($orderId)
    });

    expect($orderId)->not->toBeNull();

    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert->status)->toBe('pending');
    expect($cert->api_id)->toBeNull();

    // 扣费已落库（外层事务已提交）
    expect($user->refresh()->balance)->toBe('100.00');
    expect(Transaction::withoutGlobalScopes()
        ->where('type', 'order')->where('transaction_id', $orderId)->count())->toBe(1);
});

// ====================================================================
// reissue 所有权校验：跨用户 order_id → 'Order not found' + 整笔回滚
// ====================================================================

test('reissue 跨用户 order_id：所有权校验在事务内触发 Order not found，reissue 建的证书整笔回滚', function () {
    bindCommitOkStub();

    $owner = $this->createTestUser(['balance' => '200.00']);
    $attacker = $this->createTestUser(['balance' => '200.00']);
    $product = commitResProduct('100.00');

    // owner 先有一张 active 证书订单供重签
    $order = $owner->orders()->create([
        'product_id' => $product->id,
        'brand' => $product->brand,
        'period' => 12,
        'amount' => '100.00',
        'period_from' => now(),
        'period_till' => now()->addYear(),
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $activeCert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'amount' => '100.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
        'common_name' => 'reissue-owner.example.com',
        'alternative_names' => 'reissue-owner.example.com',
    ]);
    $order->update(['latest_cert_id' => $activeCert->id]);

    $certCountBefore = Cert::withoutGlobalScopes()->where('order_id', $order->id)->count();

    // attacker 用自己的 token 对 owner 的 order_id 发 reissue
    // 注意：Action::reissue 建单不带 user_id 过滤（UserScope 在 reissue 内 Order::find 会按 attacker 隔离，
    // 但控制器的所有权校验 where('user_id', attacker) 是关键防线，本用例验证它触发回滚）。
    $response = commitResPost($attacker, '/api/v2/reissue', [
        'order_id' => $order->id,
        'csr_generate' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    // owner 的 active 证书未被改动、未新增证书行（reissue 建的证书随事务回滚）
    expect(Cert::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe($certCountBefore);
    expect($activeCert->fresh()->status)->toBe('active');

    // 双方余额无变化（attacker 未扣费、owner 未动）
    expect($owner->refresh()->balance)->toBe('200.00');
    expect($attacker->refresh()->balance)->toBe('200.00');
});
