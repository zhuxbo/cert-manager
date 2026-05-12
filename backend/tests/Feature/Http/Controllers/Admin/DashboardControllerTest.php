<?php

use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取首页统计总览', function () {
    User::factory()->count(3)->create();
    $product = Product::factory()->create();
    Order::factory()->count(2)->create(['product_id' => $product->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/overview');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['total_users', 'total_orders', 'total_revenue', 'active_orders']]);
});

test('管理员可以获取系统概览统计', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/system-overview');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['monthly', 'daily', 'finance', 'new_users', 'new_orders']]);
});

test('管理员可以获取实时统计数据', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/realtime');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['online_users', 'today', 'alerts']]);
});

test('管理员可以获取趋势数据', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/trends?days=30');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以指定趋势天数', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/trends?days=7');

    $response->assertOk()->assertJson(['code' => 1]);
    expect(count($response->json('data')))->toBe(7);
});

test('管理员可以获取产品销售排行', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/top-products?days=30&limit=10');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以获取CA品牌统计', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/brand-stats?days=30');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以获取用户等级分布', function () {
    User::factory()->count(3)->create(['level_code' => 'standard']);
    User::factory()->count(2)->create(['level_code' => 'gold']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/user-level-distribution');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以获取财务概览', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/dashboard/finance-overview');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['total_balance', 'positive_count', 'total_debt', 'negative_count']]);
});

test('管理员可以清除仪表盘缓存', function () {
    // 先写入一些缓存
    Cache::put('dashboard:admin:overview', ['test' => 1], 3600);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/dashboard/clear-cache');

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Cache::has('dashboard:admin:overview'))->toBeFalse();
});

test('未认证用户无法访问仪表盘', function () {
    $response = $this->getJson('/api/admin/dashboard/overview');

    $response->assertUnauthorized();
});
