<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;
use Tests\Traits\CreatesTestData;
use Tests\Traits\MocksExternalApis;

uses(ActsAsAdmin::class);
uses(MocksExternalApis::class);
uses(CreatesTestData::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
    $this->product = Product::factory()->create();
});

function createOrderWithCert(string $status = 'pending', array $orderOverrides = [], array $certOverrides = []): array
{
    $order = Order::factory()->create(array_merge([
        'user_id' => test()->user->id,
        'product_id' => test()->product->id,
    ], $orderOverrides));

    $cert = Cert::factory()->create(array_merge([
        'order_id' => $order->id,
        'status' => $status,
    ], $certOverrides));

    $order->update(['latest_cert_id' => $cert->id]);

    return [$order, $cert];
}

test('管理员可以获取订单列表', function () {
    [$order, $cert] = createOrderWithCert('pending');

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/order');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items'))->toHaveCount(1);
    expect($response->json('data.items.0.id'))->toBe($order->id);
});

test('管理员可以筛选活动中的订单', function () {
    createOrderWithCert('active');
    createOrderWithCert('cancelled');

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/order?statusSet=activating');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以筛选已存档的订单', function () {
    createOrderWithCert('active');
    createOrderWithCert('cancelled');

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/order?statusSet=archived');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以通过快速搜索筛选订单', function () {
    [$order, $cert] = createOrderWithCert('pending', ['remark' => 'special order']);
    createOrderWithCert('pending');

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/order?quickSearch=special');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.id'))->toBe($order->id);
});

test('管理员可以按用户ID筛选订单', function () {
    createOrderWithCert('pending');
    $otherUser = User::factory()->create();
    $otherOrder = Order::factory()->create(['user_id' => $otherUser->id, 'product_id' => $this->product->id]);
    $otherCert = Cert::factory()->create(['order_id' => $otherOrder->id, 'status' => 'pending']);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/order?user_id={$this->user->id}");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.user_id'))->toBe($this->user->id);
});

test('列表返回 period_from period_till 和证书时间字段', function () {
    [$order, $cert] = createOrderWithCert('active', [
        'period_from' => now(),
        'period_till' => now()->addYear(),
    ], [
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
    ]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/order?statusSet=all');

    $response->assertOk()->assertJson(['code' => 1]);
    $item = collect($response->json('data.items'))->firstWhere('id', $order->id);
    expect($item)->not->toBeNull()
        ->and($item)->toHaveKeys(['period_from', 'period_till'])
        ->and($item['latest_cert'])->toHaveKeys(['issued_at', 'expires_at']);
});

test('列表支持按 period_till 排序', function () {
    [$olderOrder] = createOrderWithCert('active', ['period_till' => now()->addMonths(6)]);
    [$newerOrder] = createOrderWithCert('active', ['period_till' => now()->addYear()]);

    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/order?statusSet=all&sort_prop=period_till&sort_order=asc');

    $response->assertOk()->assertJson(['code' => 1]);
    $ids = collect($response->json('data.items'))->pluck('id')->all();
    expect(array_search($olderOrder->id, $ids))->toBeLessThan(array_search($newerOrder->id, $ids));
});

test('列表支持按 expires_at 排序', function () {
    [$olderOrder] = createOrderWithCert('active', [], ['expires_at' => now()->addMonths(6)]);
    [$newerOrder] = createOrderWithCert('active', [], ['expires_at' => now()->addYear()]);

    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/order?statusSet=all&sort_prop=expires_at&sort_order=asc');

    $response->assertOk()->assertJson(['code' => 1]);
    $ids = collect($response->json('data.items'))->pluck('id')->all();
    expect(array_search($olderOrder->id, $ids))->toBeLessThan(array_search($newerOrder->id, $ids));
});

test('管理员可以查看订单详情', function () {
    [$order, $cert] = createOrderWithCert('pending');

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/order/$order->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $order->id);
});

test('查看不存在的订单返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/order/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以创建新订单', function () {
    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('new')
        ->once()
        ->withArgs(function (array $params): bool {
            return $params['action'] === 'new'
                && $params['channel'] === 'admin'
                && $params['user_id'] === test()->user->id
                && $params['product_id'] === test()->product->id
                && $params['period'] === 12
                && $params['common_name'] === 'test.com';
        });
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/order/new', [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'period' => 12,
        'common_name' => 'test.com',
        'dcv' => [['domain' => 'test.com', 'method' => 'txt']],
    ]);

    $response->assertOk();
});

test('管理员可以支付订单', function () {
    [$order, $cert] = createOrderWithCert('unpaid');

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('pay')
        ->once()
        ->with($order->id, true, true);
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/order/pay/$order->id");

    $response->assertOk();
});

test('管理员可以提交订单', function () {
    [$order, $cert] = createOrderWithCert('pending');

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('commit')
        ->once()
        ->with($order->id);
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/order/commit/$order->id");

    $response->assertOk();
});

test('管理员可以同步订单', function () {
    [$order, $cert] = createOrderWithCert('processing');

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('sync')
        ->once()
        ->with($order->id);
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/order/sync/$order->id");

    $response->assertOk();
});

test('管理员可以提交取消订单', function () {
    [$order, $cert] = createOrderWithCert('active');

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('commitCancel')
        ->once()
        ->with($order->id);
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/order/commit-cancel/$order->id");

    $response->assertOk();
});

test('管理员可以标记订单已续费', function () {
    [$order] = createOrderWithCert('active');

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('markRenewed')
        ->once()
        ->with($order->id);
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/order/mark-renewed/$order->id");

    $response->assertOk();
});

test('管理员标记已续费-active + 到期前 25 天真实标记为 renewed', function () {
    [$order, $cert] = createOrderWithCert('active', ['period_till' => now()->addDays(25)]);

    $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/order/mark-renewed/$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($cert->fresh()->status)->toBe('renewed');
});

test('管理员标记已续费-到期 40 天后被拒（超 30 天），状态不变', function () {
    [$order, $cert] = createOrderWithCert('active', ['period_till' => now()->addDays(40)]);

    $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/order/mark-renewed/$order->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($cert->fresh()->status)->toBe('active');
});

// ==================== 真实接线 happy path（不 mock Action）====================
//
// 上面的 pay/commit/sync/commit-cancel 用例 mock 了 Action，只验证「控制器调到了
// Action 方法 + 入参对」。这里补一条端到端：不 mock Action，真实跑 charge → commit，
// 仅 mock 最底层上游 Api（Action::__construct 走 app(Api::class)，容器替身生效），
// 验证「pay/{id} → charge 真实扣费 + 锁内入账 → commit 真实写 api_id/status」整条接线。

/**
 * 造一个待扣费订单：unpaid 证书（action=new）+ 指定金额，挂上 latest_cert_id。
 * 建 ProductPrice 让 charge 组装交易备注走真实价格路径。
 */
function createAdminUnpaidOrder(User $user, Product $product, string $amount): array
{
    ProductPrice::firstOrCreate(
        ['product_id' => $product->id, 'level_code' => 'standard', 'period' => 12],
        ['price' => $amount, 'alternative_standard_price' => '10.00', 'alternative_wildcard_price' => '20.00'],
    );

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
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

test('管理员 pay→commit 端到端：真实扣费 + 真实提交上游（不 mock Action）', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();
    [$order, $cert] = createAdminUnpaidOrder($user, $product, '100.00');

    // mock 最底层上游 Api（commit 内 $this->api->new($data)）：返回带 api_id 的成功响应
    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('new')
        ->once()
        ->andReturn([
            'code' => 1,
            'data' => [
                'api_id' => 'CA-ADMIN-123',
                'cert_apply_status' => 0,
                'dcv' => [['domain' => 'test.com', 'method' => 'txt']],
                'validation' => [],
            ],
        ]);
    $this->app->instance(Api::class, $mockApi);

    // issue_verify=false 跳过 DNS 网络校验；commit=true（默认）→ 扣费成功后立即提交
    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/order/pay/$order->id", [
        'issue_verify' => false,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    // 真实扣费：余额 100 → 0，且产生一条 type=order 的扣费流水（-100）
    $user->refresh();
    expect((float) $user->balance)->toBe(0.0);
    $tx = Transaction::where('transaction_id', $order->id)->where('type', 'order')->get();
    expect($tx)->toHaveCount(1)
        ->and((float) $tx->first()->amount)->toBe(-100.0);

    // 真实提交：cert 写回上游 api_id，状态 unpaid → pending → processing
    $cert->refresh();
    expect($cert->status)->toBe('processing')
        ->and($cert->api_id)->toBe('CA-ADMIN-123');
});

test('管理员可以添加订单备注', function () {
    [$order, $cert] = createOrderWithCert('pending');

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('remark')
        ->once()
        ->with($order->id, '测试备注', 'admin_remark');
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/order/remark/$order->id", [
        'remark' => '测试备注',
    ]);

    $response->assertOk();
});

test('管理员可以转移订单', function () {
    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('transfer')
        ->once()
        ->withArgs(function (array $params): bool {
            return $params['order_id'] === 1
                && $params['user_id'] === test()->user->id;
        });
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/order/transfer', [
        'order_id' => 1,
        'user_id' => $this->user->id,
    ]);

    $response->assertOk();
});

test('管理员可以修改未支付订单价格', function () {
    [$order, $cert] = createOrderWithCert('unpaid');

    $response = $this->actingAsAdmin($this->admin)->patchJson("/api/admin/order/amount/$order->id", [
        'amount' => '200.00',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $cert->refresh();
    expect($cert->amount)->toBe('200.00');
});

test('管理员不能修改已支付订单价格', function () {
    [$order, $cert] = createOrderWithCert('active');

    $response = $this->actingAsAdmin($this->admin)->patchJson("/api/admin/order/amount/$order->id", [
        'amount' => '200.00',
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($cert->fresh()->amount)->not->toBe('200.00');
});

test('管理员可以更新订单自动续费设置', function () {
    [$order, $cert] = createOrderWithCert('active');

    $response = $this->actingAsAdmin($this->admin)->patchJson("/api/admin/order/auto-settings/$order->id", [
        'auto_renew' => true,
        'auto_reissue' => false,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.auto_renew', true);
    $response->assertJsonPath('data.auto_reissue', false);

    $order->refresh();
    expect((bool) $order->auto_renew)->toBeTrue();
    expect((bool) $order->auto_reissue)->toBeFalse();
});

test('管理员可以批量获取订单', function () {
    [$order1, $cert1] = createOrderWithCert('pending');
    [$order2, $cert2] = createOrderWithCert('active');

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/order/batch?ids[]='.$order1->id.'&ids[]='.$order2->id);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data'))->toHaveCount(2);
    $returnedIds = array_column($response->json('data'), 'id');
    sort($returnedIds);
    $expectedIds = [$order1->id, $order2->id];
    sort($expectedIds);
    expect($returnedIds)->toBe($expectedIds);
});

test('未认证用户无法访问订单管理', function () {
    $response = $this->getJson('/api/admin/order');

    $response->assertUnauthorized();
});
