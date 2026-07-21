<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

beforeEach(function () {
    Cache::flush();
});

test('获取仪表盘总览', function () {
    $user = User::factory()->withBalance('500.00')->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/overview')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['user_info', 'assets', 'orders']]);
});

test('获取资产统计', function () {
    $user = User::factory()->withBalance('1000.00')->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/assets')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['balance']]);
});

test('获取订单统计', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    // 创建一些订单
    for ($i = 0; $i < 3; $i++) {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $cert = Cert::factory()->active()->create([
            'order_id' => $order->id,
        ]);
        $order->update(['latest_cert_id' => $cert->id]);
    }

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/orders')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['total_orders', 'active_orders']]);
});

function seedUserDashboardTransaction(User $user, string $type, float $amount, int $transactionId): void
{
    DB::transaction(fn () => Transaction::create([
        'user_id' => $user->id,
        'type' => $type,
        'transaction_id' => $transactionId,
        'amount' => $amount,
    ]));
}

test('用户首页订单取消净增按本人交易流水及交易时间统计', function () {
    $user = User::factory()->withBalance('1000')->create();
    $other = User::factory()->withBalance('1000')->create();
    $product = Product::factory()->create();
    $oldOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'created_at' => now()->subDays(10),
    ]);

    seedUserDashboardTransaction($user, 'order', -100, $oldOrder->id);
    seedUserDashboardTransaction($user, 'order', -20, $oldOrder->id);
    seedUserDashboardTransaction($user, 'acme_order', -30, 91001);
    seedUserDashboardTransaction($user, 'cancel', 50, $oldOrder->id);
    seedUserDashboardTransaction($user, 'acme_cancel', 10, 91001);
    seedUserDashboardTransaction($user, 'deduct', -5, 91002);
    seedUserDashboardTransaction($other, 'order', -10, 92001);

    $orders = $this->actingAsUser($user)->getJson('/api/dashboard/orders');
    $orders->assertOk()
        ->assertJsonPath('data.total_orders', 3)
        ->assertJsonPath('data.cancelled_orders', 2)
        ->assertJsonPath('data.net_orders', 1)
        ->assertJsonPath('data.monthly_orders', 3)
        ->assertJsonPath('data.monthly_cancelled_orders', 2)
        ->assertJsonPath('data.monthly_net_orders', 1);

    $trend = $this->actingAsUser($user)->getJson('/api/dashboard/trend?days=7');
    $today = collect($trend->json('data'))->firstWhere('date', now()->format('Y-m-d'));
    expect($today)->toMatchArray([
        'orders' => 3,
        'cancelled_orders' => 2,
        'net_orders' => 1,
    ]);

    $comparison = $this->actingAsUser($user)->getJson('/api/dashboard/monthly-comparison');
    $comparison->assertJsonPath('data.current_month.orders', 3)
        ->assertJsonPath('data.current_month.cancelled_orders', 2)
        ->assertJsonPath('data.current_month.net_orders', 1);
});

test('获取趋势数据', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/trend?days=30')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取趋势数据-天数限制最小7天', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/trend?days=3')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取月度统计对比', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/dashboard/monthly-comparison')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['current_month', 'last_month', 'growth']]);
});

test('仪表盘-未认证', function () {
    $this->getJson('/api/dashboard/overview')
        ->assertUnauthorized();
});
