<?php

use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('admin 看到全部用户的凭证，但不含 credentials 明文', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    foreach ([$u1, $u2] as $u) {
        CloudDeployAccess::create([
            'user_id' => $u->id, 'name' => 'acc', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'SECRET789'],
        ]);
    }

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/access')
        ->assertOk()->assertJson(['code' => 1]);

    expect($res->json('data.total'))->toBe(2);
    expect(json_encode($res->json()))->not->toContain('SECRET789');
});

test('admin 可按 user_id 筛选', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    foreach ([$u1, $u2] as $u) {
        CloudDeployAccess::create([
            'user_id' => $u->id, 'name' => 'acc', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
        ]);
    }

    $res = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/access?user_id={$u1->id}")
        ->assertOk();

    expect($res->json('data.total'))->toBe(1);
});

test('admin 可读取 provider catalog 供 schema 表单渲染', function () {
    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/providers')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($res->json('data.providers'))->toBeArray()->not->toBeEmpty();
});

test('admin 可为订单所属用户新增部署目标', function () {
    $owner = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $owner->id,
        'name' => 'aliyun',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/target', [
            'access_id' => $access->id,
            'order_id' => $order->id,
            'product' => 'cdn',
            'config' => ['domain' => 'cdn.example.com'],
            'enabled' => true,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $target = CloudDeployTarget::withoutGlobalScopes()->first();
    expect($target)->not->toBeNull();
    expect((int) $target->user_id)->toBe((int) $owner->id);
    expect((int) $target->order_id)->toBe((int) $order->id);
    expect((int) $target->access_id)->toBe((int) $access->id);
});

test('admin 新增部署目标时拒绝跨用户混绑凭证和订单', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $access = CloudDeployAccess::create([
        'user_id' => $other->id,
        'name' => 'aliyun-other',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $owner->id]);

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/cloud-deploy/target', [
            'access_id' => $access->id,
            'order_id' => $order->id,
            'product' => 'cdn',
            'config' => ['domain' => 'cdn.example.com'],
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->count())->toBe(0);
});
