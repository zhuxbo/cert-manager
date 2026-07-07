<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('admin order-options 不带 user_id 不返回全局订单', function () {
    $owner = User::factory()->create();
    $cert = Cert::factory()->active()->create(['common_name' => 'admin-option.example.com']);
    $order = Order::factory()->create(['user_id' => $owner->id, 'latest_cert_id' => $cert->id]);
    $cert->update(['order_id' => $order->id]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/order-options?quickSearch=admin-option.example.com')
        ->assertOk();

    expect($res->json('code'))->toBe(0);
});

test('admin order-options 按 user_id、订单号和域名搜索', function () {
    $owner = User::factory()->create();
    $cert = Cert::factory()->active()->create(['common_name' => 'admin-search.example.com']);
    $order = Order::factory()->create(['user_id' => $owner->id, 'latest_cert_id' => $cert->id]);
    $cert->update(['order_id' => $order->id]);

    $byDomain = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/order-options?user_id={$owner->id}&quickSearch=admin-search.example.com")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($byDomain->json('data.total'))->toBe(1);
    expect($byDomain->json('data.items.0.user_id'))->toBe($owner->id);

    $byId = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/order-options?user_id={$owner->id}&quickSearch={$order->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($byId->json('data.total'))->toBe(1);
});

test('admin order-options show 必须带 user_id 且校验归属', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $cert = Cert::factory()->active()->create(['common_name' => 'owner.example.com']);
    $order = Order::factory()->create(['user_id' => $owner->id, 'latest_cert_id' => $cert->id]);
    $cert->update(['order_id' => $order->id]);

    $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/order-options/{$order->id}")
        ->assertOk()
        ->assertJson(['code' => 0]);

    $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/order-options/{$order->id}?user_id={$other->id}")
        ->assertOk()
        ->assertJson(['code' => 0]);

    $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/order-options/{$order->id}?user_id={$owner->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('admin order-options 默认排除 expired 和 cancelled 订单', function () {
    $owner = User::factory()->create();

    $expired = Cert::factory()->expired()->create(['common_name' => 'expired-admin.example.com']);
    $expiredOrder = Order::factory()->create(['user_id' => $owner->id, 'latest_cert_id' => $expired->id]);
    $expired->update(['order_id' => $expiredOrder->id]);

    $cancelledCert = Cert::factory()->active()->create(['common_name' => 'cancelled-admin.example.com']);
    $cancelledOrder = Order::factory()->cancelled()->create(['user_id' => $owner->id, 'latest_cert_id' => $cancelledCert->id]);
    $cancelledCert->update(['order_id' => $cancelledOrder->id]);

    $expiredRes = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/order-options?user_id={$owner->id}&quickSearch=expired-admin.example.com")
        ->assertOk();
    expect($expiredRes->json('data.total'))->toBe(0);

    $cancelledRes = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/order-options?user_id={$owner->id}&quickSearch=cancelled-admin.example.com")
        ->assertOk();
    expect($cancelledRes->json('data.total'))->toBe(0);
});
