<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取证书列表', function () {
    $order = Order::factory()->create();
    Cert::factory()->count(3)->create(['order_id' => $order->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/cert');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以按订单ID筛选证书', function () {
    $order1 = Order::factory()->create();
    $order2 = Order::factory()->create();
    Cert::factory()->count(2)->create(['order_id' => $order1->id]);
    Cert::factory()->create(['order_id' => $order2->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/cert?order_id=$order1->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(2);
});

test('管理员可以按域名搜索证书', function () {
    $order = Order::factory()->create();
    Cert::factory()->create(['order_id' => $order->id, 'common_name' => 'special.example.com']);
    Cert::factory()->create(['order_id' => $order->id, 'common_name' => 'other.test.com']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/cert?domain=special');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按状态筛选证书', function () {
    $order = Order::factory()->create();
    Cert::factory()->active()->create(['order_id' => $order->id]);
    Cert::factory()->expired()->create(['order_id' => $order->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/cert?status=active');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看证书详情', function () {
    $order = Order::factory()->create();
    $cert = Cert::factory()->create(['order_id' => $order->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/cert/$cert->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $cert->id);
});

test('查看不存在的证书返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/cert/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以批量获取证书', function () {
    $order = Order::factory()->create();
    $certs = Cert::factory()->count(3)->create(['order_id' => $order->id]);
    $ids = $certs->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/cert/batch?ids[]='.implode('&ids[]=', $ids));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以分页获取证书列表', function () {
    $order = Order::factory()->create();
    Cert::factory()->count(15)->create(['order_id' => $order->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/cert?currentPage=2&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 2);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBe(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问证书管理', function () {
    $response = $this->getJson('/api/admin/cert');

    $response->assertUnauthorized();
});
