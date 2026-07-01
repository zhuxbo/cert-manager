<?php

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
use App\Http\Controllers\V1\ApiController;
use App\Models\ApiToken;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Traits\CreatesTestData;

/**
 * M1 —— V1 一条龙（new/renew/reissue）拆出上游 commit 调用的端到端回归。
 *
 * V1 是 V2 老契约镜像，同样单事务整笔回滚。改造与 V2 同构：外层事务只包 new + pay(commit=false)，
 * commit 移到事务外，getData('commit') 吞 code=0 / MutationBusyException。V1 响应壳 getProcessStatus()
 * 不动（processing 对 V1 客户端透明）。
 *
 * 详见同目录 V2/ApiControllerCommitResilienceTest 说明。V1 路由前缀为 /api/V1（大写）。
 */
uses(CreatesTestData::class);

beforeEach(function () {
    Cache::flush();
});

// ── 辅助函数 ──

function v1CrAuthHeaders(User $user): array
{
    $plainToken = ApiToken::createToken($user->id);

    return ['Authorization' => "Bearer $plainToken"];
}

function v1CrPost(User $user, string $uri, array $data = []): TestResponse
{
    return test()->withHeaders(v1CrAuthHeaders($user))->postJson($uri, $data);
}

function v1CrProduct(string $price = '100.00'): Product
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

function v1CrCommitOkStub(): MockInterface
{
    // commit 阶段按 cert.action 调上游（new→->new / renew→->renew / reissue→->reissue），三者都桩成功
    $ok = ['code' => 1, 'data' => ['api_id' => 'stub-api-id', 'cert_apply_status' => 0]];
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('new')->andReturn($ok);
    $mock->shouldReceive('renew')->andReturn($ok);
    $mock->shouldReceive('reissue')->andReturn($ok);
    app()->instance(Api::class, $mock);

    return $mock;
}

function v1CrCommitTimeoutStub(): MockInterface
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
// V1 new 成功
// ====================================================================

test('V1 new 成功：commit 落库 api_id + processing，响应含 oid', function () {
    v1CrCommitOkStub();

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = v1CrProduct('100.00');

    $response = v1CrPost($user, '/api/V1/new', [
        'pid' => $product->code,
        'period' => 12,
        'domains' => 'v1-commit-ok.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $orderId = $response->json('data.oid');
    expect($orderId)->not->toBeNull();

    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert->status)->toBe('processing');
    expect($cert->api_id)->toBe('stub-api-id');

    expect($user->refresh()->balance)->toBe('100.00');
    expect(Transaction::withoutGlobalScopes()
        ->where('type', 'order')->where('transaction_id', $orderId)->count())->toBe(1);
});

// ====================================================================
// V1 new commit 超时（核心）
// ====================================================================

test('V1 new commit 超时（SDK code=0）：订单不回滚、cert 停 pending、api_id 空、扣费保留、200 + msg 不泄露', function () {
    v1CrCommitTimeoutStub();

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = v1CrProduct('100.00');

    $response = v1CrPost($user, '/api/V1/new', [
        'pid' => $product->code,
        'period' => 12,
        'domains' => 'v1-commit-timeout.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    // HTTP 200 + code=1（不因 commit 超时报错）
    $response->assertOk()->assertJson(['code' => 1]);
    $orderId = $response->json('data.oid');
    expect($orderId)->not->toBeNull();

    // 订单未回滚，cert 停 pending，api_id 空
    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order)->not->toBeNull();
    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert->status)->toBe('pending');
    expect($cert->api_id)->toBeNull();

    // 扣费仍在
    expect($user->refresh()->balance)->toBe('100.00');
    expect(Transaction::withoutGlobalScopes()
        ->where('type', 'order')->where('transaction_id', $orderId)->count())->toBe(1);

    // msg 不泄露内部地址 / Guzzle 原文
    $body = $response->getContent();
    expect($body)->not->toContain('upstream.test');
    expect($body)->not->toContain('https://');
    expect($body)->not->toContain('Request failed');
    expect($body)->not->toContain('cURL');
});

test('V1 get 命中 pending 且 commit 抢锁忙：不冒 503，返回 processing 展示态', function () {
    $user = $this->createTestUser(['balance' => '200.00']);
    $product = v1CrProduct('100.00');
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'pending',
        'api_id' => null,
        'common_name' => 'get-busy-v1.example.com',
        'alternative_names' => 'get-busy-v1.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->once()->with($order->id)->andThrow(
        new MutationBusyException("order_mutate_{$order->id}")
    );
    app()->instance(Action::class, $action);

    $response = $this->withHeaders(v1CrAuthHeaders($user))
        ->postJson('/api/V1/get', ['oid' => $order->id]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.status'))->toBe('processing');
    expect($cert->fresh()->status)->toBe('pending');
});

// ====================================================================
// V1 refer_id 幂等推进
// ====================================================================

test('V1 同 refer_id 重试命中 pending 卡单：不硬拒，重提 commit 后返回同一 oid', function () {
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
    $product = v1CrProduct('100.00');
    $referId = 'm5pendingretryv10000000000000000';

    $first = v1CrPost($user, '/api/V1/new', [
        'refer_id' => $referId,
        'pid' => $product->code,
        'period' => 12,
        'domains' => 'm5-pending-v1.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $first->assertOk()->assertJson(['code' => 1]);
    $orderId = $first->json('data.oid');
    expect(Cert::withoutGlobalScopes()->where('order_id', $orderId)->value('status'))->toBe('pending');

    $second = v1CrPost($user, '/api/V1/new', [
        'refer_id' => $referId,
        'pid' => $product->code,
        'period' => 12,
        'domains' => 'm5-pending-v1.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $second->assertOk()->assertJson(['code' => 1]);
    expect($second->json('data.oid'))->toBe($orderId);

    $cert = Cert::withoutGlobalScopes()->where('order_id', $orderId)->latest('id')->first();
    expect($cert->status)->toBe('processing');
    expect($cert->api_id)->toBe('stub-api-id');
    expect(Order::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1);
});

// 守卫回归（钉死）：resolveReferId 重提 commit 条件为 `! api_id && status === 'pending'`，
// 终态订单（cancelled 等，api_id 可能仍空）绝不能被重提 commit 复活。
test('V1 同 refer_id 命中终态订单（cancelled/api_id 空）：不重提上游 commit、不复活，幂等返回既有 oid', function () {
    // 直接断言控制器层守卫：终态订单绝不调 Action::commit —— 唯一钉死控制器守卫（不依赖 commitLocked 兜底，round-2 L-1）
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->never();
    app()->instance(Action::class, $action);

    $user = $this->createTestUser(['balance' => '200.00']);
    $product = v1CrProduct('100.00');
    $referId = 'm5terminalv100000000000000000000';

    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'cancelled',
        'api_id' => null,
        'refer_id' => $referId,
        'common_name' => 'm5-terminal-v1.example.com',
        'alternative_names' => 'm5-terminal-v1.example.com',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $response = v1CrPost($user, '/api/V1/new', [
        'refer_id' => $referId,
        'pid' => $product->code,
        'period' => 12,
        'domains' => 'ignored.example.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.oid'))->toBe($order->id);

    expect($cert->fresh()->status)->toBe('cancelled');
    expect($cert->fresh()->api_id)->toBeNull();
    expect(Order::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1);
});

// ====================================================================
// V1 reissue 跨用户所有权校验：'Order not found' + 整笔回滚
// ====================================================================

test('V1 reissue 跨用户 oid：所有权校验触发 Order not found，reissue 建的证书整笔回滚', function () {
    v1CrCommitOkStub();

    $owner = $this->createTestUser(['balance' => '200.00']);
    $attacker = $this->createTestUser(['balance' => '200.00']);
    $product = v1CrProduct('100.00');

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
        'common_name' => 'v1-reissue-owner.example.com',
        'alternative_names' => 'v1-reissue-owner.example.com',
    ]);
    $order->update(['latest_cert_id' => $activeCert->id]);

    $certCountBefore = Cert::withoutGlobalScopes()->where('order_id', $order->id)->count();

    $response = v1CrPost($attacker, '/api/V1/reissue', [
        'oid' => $order->id,
        'csr_generate' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect(Cert::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe($certCountBefore);
    expect($activeCert->fresh()->status)->toBe('active');

    expect($owner->refresh()->balance)->toBe('200.00');
    expect($attacker->refresh()->balance)->toBe('200.00');
});

// ====================================================================
// V1 getData 分流单测（定向）：commit 段吞 code=0 / MutationBusyException
// ====================================================================

function callV1GetData(Action $action, string $act, array $params): array
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

test('V1 getData: commit 段吞 SDK code=0，返回 []', function () {
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->once()->andThrow(
        new ApiResponseException(
            'Request failed: cURL error 28 https://upstream.test/x', null, null, 0
        )
    );

    expect(callV1GetData($action, 'commit', [123]))->toBe([]);
});

test('V1 getData: commit 段吞 MutationBusyException，返回 []', function () {
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('commit')->once()->andThrow(
        new MutationBusyException('order_mutate_123')
    );

    expect(callV1GetData($action, 'commit', [123]))->toBe([]);
});

test('V1 getData: new 段 code=0 仍冒泡', function () {
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('new')->once()->andThrow(
        new ApiResponseException('余额不足', null, null, 0)
    );

    expect(fn () => callV1GetData($action, 'new', [['x' => 1]]))
        ->toThrow(ApiResponseException::class);
});
