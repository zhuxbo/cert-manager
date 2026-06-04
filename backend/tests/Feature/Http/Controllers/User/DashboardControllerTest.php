<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
