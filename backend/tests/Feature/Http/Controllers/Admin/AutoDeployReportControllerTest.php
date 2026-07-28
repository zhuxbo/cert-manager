<?php

use App\Models\Admin;
use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class, RefreshDatabase::class);

test('管理员可以分页筛选全部自动部署记录', function () {
    $admin = Admin::factory()->create();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $otherOrder = Order::factory()->create(['user_id' => $otherUser->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id, 'common_name' => 'deploy.example.com']);
    $otherCert = Cert::factory()->create(['order_id' => $otherOrder->id]);

    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'failure',
        'ip' => '203.0.113.10',
        'message' => '部署失败',
    ]);
    AutoDeployReport::create([
        'order_id' => $otherOrder->id,
        'cert_id' => $otherCert->id,
        'status' => 'success',
        'ip' => '203.0.113.11',
    ]);

    $response = $this->actingAsAdmin($admin)
        ->getJson("/api/admin/auto-deploy-report?status=failure&user_id={$user->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))->toBe(1)
        ->and($response->json('data.pageSize'))->toBe(10)
        ->and($response->json('data.items.0.order.user.id'))->toBe($user->id)
        ->and($response->json('data.items.0.cert.common_name'))->toBe('deploy.example.com');
});

test('未认证管理员不能查看自动部署记录', function () {
    $this->getJson('/api/admin/auto-deploy-report')->assertUnauthorized();
});

test('管理员可以按页面显示时间筛选自动部署记录', function () {
    $admin = Admin::factory()->create();
    $order = Order::factory()->create();
    $cert = Cert::factory()->create(['order_id' => $order->id]);

    $failure = AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'failure',
        'ip' => '203.0.113.30',
    ]);
    $failure->forceFill(['created_at' => '2026-07-10 08:00:00'])->save();

    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'success',
        'deployed_at' => '2026-07-11 08:00:00',
        'ip' => '203.0.113.31',
    ]);

    $query = http_build_query([
        'order_id' => $order->id,
        'time' => ['2026-07-10T00:00:00.000Z', '2026-07-10T23:59:59.999Z'],
    ]);
    $response = $this->actingAsAdmin($admin)
        ->getJson("/api/admin/auto-deploy-report?{$query}")
        ->assertOk();

    expect($response->json('data.total'))->toBe(1)
        ->and($response->json('data.items.0.ip'))->toBe('203.0.113.30');
});
