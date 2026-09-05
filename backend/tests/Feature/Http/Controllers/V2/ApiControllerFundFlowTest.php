<?php

use App\Exceptions\ApiResponseException;
use App\Models\ApiToken;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Api\Api;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Traits\CreatesTestData;

/**
 * V2 对外 API 资金路径的「真实路由 + 真实 V2 token」端到端回归（审核 #9）。
 *
 * 与同目录 ApiControllerDcvCleanTest / ApiControllerCoreBranchTest 的区别：
 * 那两个文件用 ReflectionClass::newInstanceWithoutConstructor() 伪造控制器、Mockery::mock(Action)，
 * 绕过路由 / api.v2 中间件 / token 鉴权 / UserScope，只覆盖字段过滤 + delegation 拦截 + cancel 幂等。
 *
 * 本文件全程走 routes/api.v2.php 真实路由 + 真实加密 token + ApiAuthenticate（注册 UserScope），
 * Action 用容器真身（app(Action::class)），仅把上游网络边界 Order\Api\Api 换成桩
 * （app()->instance(Api::class, ...)，沿用 SyncedCancelRefundTest 的既有做法）——
 * 不 mock Action 本身，扣费 / 退费 / UserScope 出口隔离全部经真实代码路径。
 *
 * 重点是控制器 wiring + 出口隔离，底层资金逻辑已由 Unit/Services/Order/ActionTest
 * 与 Feature/Services/Order/SyncedCancelRefundTest 充分覆盖。
 */
uses(CreatesTestData::class);

beforeEach(function () {
    // get/new 内部按 order_id 写 api_get_ 缓存做节流，逐用例清空避免串扰
    Cache::store('runtime')->flush();
});

// ── 辅助函数 ──

/**
 * 真实 V2 token 请求头：createToken 返回明文，DB 存 sha256(明文)，
 * ApiAuthenticate 经 ExtendedTokenGuard 解析后挂 UserScope 到当前 user。
 */
function v2AuthHeaders(User $user): array
{
    $plainToken = ApiToken::createToken($user->id);

    return ['Authorization' => "Bearer $plainToken"];
}

function v2Post(User $user, string $uri, array $data = []): TestResponse
{
    return test()->withHeaders(v2AuthHeaders($user))->postJson($uri, $data);
}

function v2Get(User $user, string $uri): TestResponse
{
    return test()->withHeaders(v2AuthHeaders($user))->getJson($uri);
}

/**
 * 把上游 Order\Api\Api 换成桩；Action::__construct 走 app(Api::class)，容器绑定即生效。
 * 控制器 new→pay→commit / cancel 会调到这些方法，桩返回成功以驱动本地资金状态机。
 */
function bindOrderApiStub(): MockInterface
{
    $mock = Mockery::mock(Api::class);
    // commit 时按 cert.action 调上游（new 流程即 ->new($data)），返回 api_id 让本地置 processing
    $mock->shouldReceive('new')->andReturn([
        'code' => 1,
        'data' => ['api_id' => 'stub-api-id', 'cert_apply_status' => 0],
    ]);
    $mock->shouldReceive('cancel')->andReturn(['code' => 1, 'data' => []]);

    app()->instance(Api::class, $mock);

    return $mock;
}

/**
 * 造一个「已支付 active」订单 + 一条 -amount 的 order 扣费流水。
 *
 * 注意余额语义：扣费流水是真实负数交易，会经 Transaction::creating 钩子把 user.balance 减去 amount。
 * 因此调用方应让 user 初始余额 = 期望的「下单后余额」+ amount，使本函数执行后落到下单后余额。
 * 例如 createTestUser(balance=200) + amount=100 → 本函数后 balance=100（下单后），退费 +100 → 200。
 *
 * $createdAt 用于 refund_period 场景：Order::create 会被时间戳自动覆盖，故创建后强制回写。
 */
function v2ActivePaidOrder(User $user, Product $product, string $amount = '100.00', array $certOverrides = [], array $orderOverrides = [], ?Carbon $createdAt = null): array
{
    $order = $user->orders()->create(array_merge([
        'product_id' => $product->id,
        'brand' => $product->brand,
        'period' => 12,
        'amount' => $amount,
        'period_from' => now(),
        'period_till' => now()->addYear(),
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ], $orderOverrides));

    $cert = Cert::factory()->active()->create(array_merge([
        'order_id' => $order->id,
        'amount' => $amount,
        'standard_count' => 1,
        'wildcard_count' => 0,
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nCA_BODY\n-----END CERTIFICATE-----",
    ], $certOverrides));

    $order->update(['latest_cert_id' => $cert->id]);

    // Eloquent 时间戳会覆盖 create 传入的 created_at，需创建后强制回写（refund_period 校验依赖它）
    if ($createdAt !== null) {
        Order::withoutGlobalScopes()->where('id', $order->id)->update(['created_at' => $createdAt]);
    }

    // 扣费流水（负数）——退费按 getCancelTransaction 汇总该流水取反；钩子会同步扣 user.balance
    Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-'.$amount,
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    return [Order::withoutGlobalScopes()->find($order->id), $cert];
}

// ====================================================================
// new — 真实路由扣费：余额扣减 + 订单/证书创建 + 状态流转
// ====================================================================

test('new 经真实路由扣费：余额扣减 + 订单/证书创建 + 上游提交置 processing', function () {
    bindOrderApiStub();

    // balance 200，产品单价 100 → 扣费后 100
    $user = $this->createTestUser(['balance' => '200.00']);
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
    // user.level_code = standard，getMinPrice 按此匹配；SAN 价置 0 让金额 = 基础价 100（断言清晰）
    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => 'standard',
        'period' => 12,
        'price' => '100.00',
        'alternative_standard_price' => '0.00',
        'alternative_wildcard_price' => '0.00',
    ]);

    $response = v2Post($user, '/api/v2/new', [
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'fund-new.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $orderId = $response->json('data.order_id');
    expect($orderId)->not->toBeNull();

    // 订单 + 证书已创建且归属当前用户
    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order)->not->toBeNull();
    expect($order->user_id)->toBe($user->id);

    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert)->not->toBeNull();
    // new→pay→commit 全程成功：扣费置 pending、上游提交置 processing
    expect($cert->status)->toBe('processing');
    expect($cert->api_id)->toBe('stub-api-id');

    // 余额扣减 100（200 → 100）
    expect($user->refresh()->balance)->toBe('100.00');

    // 恰一条 order 扣费流水，金额 -100
    $tx = Transaction::withoutGlobalScopes()
        ->where('type', 'order')->where('transaction_id', $orderId)->get();
    expect($tx)->toHaveCount(1);
    expect((float) $tx->first()->amount)->toBe(-100.0);
});

test('new 余额不足经真实路由被拒：不创建已支付订单、余额不变', function () {
    bindOrderApiStub();

    // balance 50 < 单价 100，credit_limit 0
    $user = $this->createTestUser(['balance' => '50.00']);
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
        'price' => '100.00',
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);

    $response = v2Post($user, '/api/v2/new', [
        'product_code' => $product->code,
        'period' => 12,
        'domains' => 'fund-poor.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    // 扣费失败：控制器 try/catch DB::rollBack + throw → 全局 handler 返回 code=0
    $response->assertOk()->assertJson(['code' => 0]);

    // 余额未变、无 order 扣费流水、且订单行整体回滚（控制器 new+pay 外层事务 rollBack）
    expect($user->refresh()->balance)->toBe('50.00');
    expect(Transaction::withoutGlobalScopes()->where('type', 'order')->count())->toBe(0);
    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

// ====================================================================
// cancel — 真实路由退费：金额正确 + 状态 cancelled + 幂等
// ====================================================================

test('cancel active 订单经真实路由退费：退费金额正确、cert 置 cancelled、余额回正', function () {
    bindOrderApiStub();

    // 充值 200 → 下单扣 100（fixture 内 -100 流水）→ 下单后余额 100 → 退费 +100 → 200
    $user = $this->createTestUser(['balance' => '200.00']);
    $product = Product::factory()->create(['refund_period' => 30]);
    [$order] = v2ActivePaidOrder($user, $product, '100.00');
    expect($user->refresh()->balance)->toBe('100.00'); // 前置：下单后余额

    $response = v2Post($user, '/api/v2/cancel', ['order_id' => $order->id]);

    $response->assertOk()->assertJson(['code' => 1]);

    // cert 置 cancelled、订单记录 cancelled_at
    expect($order->latestCert()->withoutGlobalScopes()->first()->status)->toBe('cancelled');
    expect($order->fresh()->cancelled_at)->not->toBeNull();

    // 退费 +100：恰一条 cancel 流水、金额 +100、余额回到 200
    $cancelTx = Transaction::withoutGlobalScopes()
        ->where('type', 'cancel')->where('transaction_id', $order->id)->get();
    expect($cancelTx)->toHaveCount(1);
    expect((float) $cancelTx->first()->amount)->toBe(100.0);
    expect($user->refresh()->balance)->toBe('200.00');
});

test('cancel 首次异常后 60 秒内重复请求被拒且不再次触达上游', function () {
    $stub = Mockery::mock(Api::class);
    $stub->shouldReceive('cancel')
        ->once()
        ->andThrow(new ApiResponseException('上游取消失败', null, null, 0));
    app()->instance(Api::class, $stub);

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = Product::factory()->create(['refund_period' => 30]);
    [$order] = v2ActivePaidOrder($user, $product, '100.00');

    v2Post($user, '/api/v2/cancel', ['order_id' => $order->id])
        ->assertOk()
        ->assertJson(['code' => 0, 'msg' => '上游取消失败']);

    $duplicate = v2Post($user, '/api/v2/cancel', ['order_id' => $order->id]);
    $duplicate->assertOk()->assertJson(['code' => 0]);

    $retryAfter = $duplicate->json('errors.retry_after');
    expect($retryAfter)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($duplicate->json('msg'))->toBe("Duplicate cancel request, retry after {$retryAfter} seconds")
        ->and($order->latestCert()->withoutGlobalScopes()->first()->status)->toBe('cancelling')
        ->and(Transaction::withoutGlobalScopes()->where('type', 'cancel')->where('transaction_id', $order->id)->exists())->toBeFalse();
});

test('cancel 已取消订单经真实路由幂等：返回成功、不重复退费、不触达上游', function () {
    $stub = bindOrderApiStub();
    // 幂等分支应在控制器内短路，不应调用上游 cancel
    $stub->shouldNotReceive('cancel');

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = Product::factory()->create(['refund_period' => 30]);
    // cert 直接 cancelled（已退费的终态），不再预置可被二次退费的流水
    $order = $user->orders()->create([
        'product_id' => $product->id,
        'brand' => $product->brand,
        'period' => 12,
        'amount' => '100.00',
        'period_from' => now(),
        'period_till' => now()->addYear(),
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
        'cancelled_at' => now(),
    ]);
    $cert = Cert::factory()->cancelled()->create([
        'order_id' => $order->id,
        'amount' => '100.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $balanceBefore = $user->refresh()->balance;

    $response = v2Post($user, '/api/v2/cancel', ['order_id' => $order->id]);

    $response->assertOk()->assertJson(['code' => 1]);

    // 余额不变、无 cancel 流水产生
    expect($user->refresh()->balance)->toBe($balanceBefore);
    expect(Transaction::withoutGlobalScopes()->where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
    expect($cert->fresh()->status)->toBe('cancelled');
});

test('cancel 超过 refund_period 经真实路由被拒：不退费、cert 仍 active、不触达上游', function () {
    $stub = bindOrderApiStub();
    // 超期在控制器层（cancel() L550）即被拒，不应调用上游
    $stub->shouldNotReceive('cancel');

    // 充值 200 → 下单扣 100 → 下单后余额 100
    $user = $this->createTestUser(['balance' => '200.00']);
    $product = Product::factory()->create(['refund_period' => 7]);
    // created_at 设为 8 天前 > refund_period(7)，触发控制器超期拒绝
    [$order] = v2ActivePaidOrder($user, $product, '100.00', [], [], now()->subDays(8));

    $response = v2Post($user, '/api/v2/cancel', ['order_id' => $order->id]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($response->json('msg'))->toContain('cannot be cancelled');

    // 未退费：余额停在下单后的 100、无 cancel 流水、cert 仍 active
    expect($user->refresh()->balance)->toBe('100.00');
    expect(Transaction::withoutGlobalScopes()->where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(0);
    expect($order->latestCert()->withoutGlobalScopes()->first()->status)->toBe('active');
});

test('cancel 跨 token 访问他人订单经真实路由：UserScope 出口隔离、视为不存在、不退费、不触达上游', function () {
    $stub = bindOrderApiStub();
    $stub->shouldNotReceive('cancel');

    // owner 充值 200 → 下单扣 100 → 下单后余额 100
    $owner = $this->createTestUser(['balance' => '200.00']);
    $attacker = $this->createTestUser(['balance' => '0.00']);
    $product = Product::factory()->create(['refund_period' => 30]);
    [$ownerOrder] = v2ActivePaidOrder($owner, $product, '100.00');

    // attacker 的 token 取消 owner 的订单
    $response = v2Post($attacker, '/api/v2/cancel', ['order_id' => $ownerOrder->id]);

    // UserScope 限制 attacker 看不到 owner 订单 → Order not found
    $response->assertOk()->assertJson(['code' => 0, 'msg' => 'Order not found']);

    // owner 订单未被取消、双方余额、流水均无变化
    expect($ownerOrder->latestCert()->withoutGlobalScopes()->first()->status)->toBe('active');
    expect($owner->refresh()->balance)->toBe('100.00');
    expect($attacker->refresh()->balance)->toBe('0.00');
    expect(Transaction::withoutGlobalScopes()->where('type', 'cancel')->where('transaction_id', $ownerOrder->id)->count())->toBe(0);
});

// ====================================================================
// download / get — 出口私钥/证书归属隔离（UserScope 在出口生效）
// ====================================================================
//
// download() 的归属隔离与 cancel/get 同源：Action::download（ActionFileTrait.php:30）
// 走 Order::with(['latestCert'])->whereIn('id', $orderIds)->get()，在 api.v2 请求上下文中
// 由 ApiAuthenticate 注册的 UserScope 强制 where('user_id', 当前 token.user)；跨 token 的
// 他人订单不在结果集 → isEmpty → 不泄漏任何证书/私钥字节。
//
// ⚠️ download() 在 isEmpty（以及成功流 downFlow）时调用裸 exit（ActionFileTrait.php:43 / 286），
// 会在 Pest/PHPUnit 进程内直接终止 PHP，无法用 TestResponse 断言（实测：进程被杀、无任何输出）。
// 因此 download 这一出口的 UserScope 归属隔离改由共享同一「->where('user_id',$this->user_id)」
// 出口、但走 $this->error()（异常而非 exit）的 get 端点做等价路由级回归。
// 详见交付说明：download 的 exit 是 route-untestable 的根因，已登记为遗留项。

test('get 跨 token 访问他人订单经真实路由：UserScope 出口隔离、视为不存在、不泄漏证书/私钥', function () {
    // get 对 active 订单本会触发 sync 上游；但跨 token 时 order 查询即被 UserScope 拦截返回 null，
    // 在调用上游前就 error('Order not found')，无需桩；预置缓存兜底双保险（避免任何 sync 触达）。
    $owner = $this->createTestUser(['balance' => '100.00']);
    $attacker = $this->createTestUser(['balance' => '0.00']);
    $product = Product::factory()->create();
    [$ownerOrder, $ownerCert] = v2ActivePaidOrder($owner, $product, '100.00', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nOWNER_SECRET_KEY\n-----END PRIVATE KEY-----",
        'cert' => "-----BEGIN CERTIFICATE-----\nOWNER_CERT\n-----END CERTIFICATE-----",
    ]);
    Cache::store('runtime')->set('api_get_'.$ownerOrder->id, time(), 120);

    $response = v2Get($attacker, '/api/v2/get?order_id='.$ownerOrder->id);

    $response->assertOk()->assertJson(['code' => 0, 'msg' => 'Order not found']);

    // 出口未泄漏 owner 的私钥/证书内容
    $body = $response->getContent();
    expect($body)->not->toContain('OWNER_SECRET_KEY');
    expect($body)->not->toContain('OWNER_CERT');
});

test('get 同 token 取回自己订单经真实路由：携带私钥（出口归属正向用例）', function () {
    // 正向对照：owner 用自己的 token 可取回订单与私钥，证明上面的隔离不是「所有人都查不到」的假阴性。
    $owner = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();
    [$ownerOrder] = v2ActivePaidOrder($owner, $product, '100.00', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nOWNER_SECRET_KEY\n-----END PRIVATE KEY-----",
    ]);
    // active 订单 get 会触发 sync 上游：预置缓存让 get 跳过 sync/pay/commit（与 V1 测试同手法）
    Cache::store('runtime')->set('api_get_'.$ownerOrder->id, time(), 120);

    $response = v2Get($owner, '/api/v2/get?order_id='.$ownerOrder->id);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.private_key'))->toContain('OWNER_SECRET_KEY');
});
