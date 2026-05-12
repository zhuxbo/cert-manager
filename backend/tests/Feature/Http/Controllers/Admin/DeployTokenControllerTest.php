<?php

use App\Models\Admin;
use App\Models\DeployToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员可以获取部署令牌列表', function () {
    DeployToken::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/deploy-token');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以按状态筛选部署令牌', function () {
    DeployToken::factory()->create(['user_id' => $this->user->id, 'status' => 1]);
    DeployToken::factory()->disabled()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/deploy-token?status=0');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看部署令牌详情', function () {
    $token = DeployToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/deploy-token/$token->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $token->id);
});

test('查看不存在的部署令牌返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/deploy-token/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以添加部署令牌', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/deploy-token', [
        'user_id' => $this->user->id,
        'token' => bin2hex(random_bytes(16)),
        'rate_limit' => 60,
        'status' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(DeployToken::where('user_id', $this->user->id)->count())->toBeGreaterThan(0);
});

test('管理员可以更新部署令牌', function () {
    $token = DeployToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/deploy-token/$token->id", [
        'rate_limit' => 120,
        'status' => 0,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $token->refresh();
    expect($token->rate_limit)->toBe(120);
    expect($token->status)->toBe(0);
});

test('管理员可以删除部署令牌', function () {
    $token = DeployToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/deploy-token/$token->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(DeployToken::find($token->id))->toBeNull();
});

test('管理员可以批量删除部署令牌', function () {
    $tokens = DeployToken::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $tokens->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/deploy-token/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(DeployToken::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以批量获取部署令牌', function () {
    $tokens = DeployToken::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $tokens->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/deploy-token/batch?ids[]='.implode('&ids[]=', $ids));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以分页获取部署令牌', function () {
    DeployToken::factory()->count(15)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/deploy-token?currentPage=2&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 2);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBe(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问部署令牌管理', function () {
    $response = $this->getJson('/api/admin/deploy-token');

    $response->assertUnauthorized();
});
