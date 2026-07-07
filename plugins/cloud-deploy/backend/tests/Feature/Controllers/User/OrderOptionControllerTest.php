<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('user order-options 按订单号和域名搜索并返回顶层 label', function () {
    $cert = Cert::factory()->active()->create(['common_name' => 'deploy.example.com']);
    $order = Order::factory()->create(['user_id' => $this->user->id, 'latest_cert_id' => $cert->id]);
    $cert->update(['order_id' => $order->id]);

    $res = $this->actingAsUser($this->user)
        ->getJson('/api/cloud-deploy/order-options?quickSearch=deploy.example.com')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.id'))->toBe($order->id);
    expect($res->json('data.items.0.label'))
        ->toContain((string) $order->id)
        ->toContain('deploy.example.com')
        ->toContain('active');

    $byId = $this->actingAsUser($this->user)
        ->getJson('/api/cloud-deploy/order-options?quickSearch='.$order->id)
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($byId->json('data.total'))->toBe(1);
});

test('user order-options 默认排除 expired 最新证书订单', function () {
    $expired = Cert::factory()->expired()->create();
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'latest_cert_id' => $expired->id,
        'period_till' => now()->addMonth(),
    ]);
    $expired->update(['order_id' => $order->id]);

    $res = $this->actingAsUser($this->user)
        ->getJson('/api/cloud-deploy/order-options?quickSearch='.$order->id)
        ->assertOk();

    expect($res->json('data.total'))->toBe(0);
});

test('user order-options 排除已取消订单并保留 pending 候选订单', function () {
    $pendingCert = Cert::factory()->create(['status' => 'pending', 'common_name' => 'pending.example.com']);
    $pendingOrder = Order::factory()->create(['user_id' => $this->user->id, 'latest_cert_id' => $pendingCert->id]);
    $pendingCert->update(['order_id' => $pendingOrder->id]);

    $cancelledCert = Cert::factory()->active()->create(['common_name' => 'cancelled.example.com']);
    $cancelledOrder = Order::factory()->cancelled()->create(['user_id' => $this->user->id, 'latest_cert_id' => $cancelledCert->id]);
    $cancelledCert->update(['order_id' => $cancelledOrder->id]);

    $pending = $this->actingAsUser($this->user)
        ->getJson('/api/cloud-deploy/order-options?quickSearch=pending.example.com')
        ->assertOk();
    expect($pending->json('data.total'))->toBe(1);

    $cancelled = $this->actingAsUser($this->user)
        ->getJson('/api/cloud-deploy/order-options?quickSearch=cancelled.example.com')
        ->assertOk();
    expect($cancelled->json('data.total'))->toBe(0);
});

test('user order-options show 只回显当前用户订单', function () {
    $cert = Cert::factory()->active()->create(['common_name' => 'own.example.com']);
    $order = Order::factory()->create(['user_id' => $this->user->id, 'latest_cert_id' => $cert->id]);
    $cert->update(['order_id' => $order->id]);

    $other = User::factory()->create();
    $otherCert = Cert::factory()->active()->create(['common_name' => 'other.example.com']);
    $otherOrder = Order::factory()->create(['user_id' => $other->id, 'latest_cert_id' => $otherCert->id]);
    $otherCert->update(['order_id' => $otherOrder->id]);

    $this->actingAsUser($this->user)
        ->getJson("/api/cloud-deploy/order-options/{$order->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $this->actingAsUser($this->user)
        ->getJson("/api/cloud-deploy/order-options/{$otherOrder->id}")
        ->assertOk()
        ->assertJson(['code' => 0]);
});
