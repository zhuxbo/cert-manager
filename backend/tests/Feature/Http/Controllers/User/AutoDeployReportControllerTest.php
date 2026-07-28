<?php

use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class, RefreshDatabase::class);

test('用户只能查看自己的自动部署记录', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $otherOrder = Order::factory()->create(['user_id' => $otherUser->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'common_name' => 'own.example.com']);
    $otherCert = Cert::factory()->create([
        'order_id' => $otherOrder->id,
        'common_name' => 'own.example.com',
    ]);

    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'success',
        'ip' => '203.0.113.20',
    ]);
    AutoDeployReport::create([
        'order_id' => $otherOrder->id,
        'cert_id' => $otherCert->id,
        'status' => 'success',
        'ip' => '203.0.113.21',
    ]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/auto-deploy-report?status=success&quickSearch=own.example.com')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))->toBe(1)
        ->and($response->json('data.pageSize'))->toBe(10)
        ->and($response->json('data.items.0.order_id'))->toBe($order->id)
        ->and($response->json('data.items.0.cert.common_name'))->toBe('own.example.com');
});

test('未认证用户不能查看自动部署记录', function () {
    $this->getJson('/api/auto-deploy-report')->assertUnauthorized();
});
