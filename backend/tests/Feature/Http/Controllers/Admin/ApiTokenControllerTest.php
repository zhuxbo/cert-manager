<?php

use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员可以获取API令牌列表', function () {
    ApiToken::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/api-token');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以按状态筛选API令牌', function () {
    ApiToken::factory()->create(['user_id' => $this->user->id, 'status' => 1]);
    ApiToken::factory()->disabled()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/api-token?status=0');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看API令牌详情', function () {
    $token = ApiToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/api-token/$token->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $token->id);
});

test('查看不存在的API令牌返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/api-token/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以添加API令牌', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/api-token', [
        'user_id' => $this->user->id,
        'token' => 'testtoken123456',
        'rate_limit' => 60,
        'status' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(ApiToken::where('user_id', $this->user->id)->count())->toBeGreaterThan(0);
});

test('管理员可以更新API令牌', function () {
    $token = ApiToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/api-token/$token->id", [
        'user_id' => $this->user->id,
        'rate_limit' => 120,
        'status' => 0,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $token->refresh();
    expect($token->rate_limit)->toBe(120);
    expect($token->status)->toBe(0);
});

test('管理员可以删除API令牌', function () {
    $token = ApiToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/api-token/$token->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(ApiToken::find($token->id))->toBeNull();
});

test('管理员可以批量删除API令牌', function () {
    $tokens = ApiToken::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $tokens->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/api-token/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(ApiToken::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以批量获取API令牌', function () {
    $tokens = ApiToken::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $tokens->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/api-token/batch?ids[]='.implode('&ids[]=', $ids));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以设置IP白名单', function () {
    $token = ApiToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/api-token/$token->id", [
        'user_id' => $this->user->id,
        'allowed_ips' => ['192.168.1.1', '10.0.0.1'],
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以分页获取API令牌', function () {
    ApiToken::factory()->count(15)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/api-token?currentPage=2&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 2);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBe(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问API令牌管理', function () {
    $response = $this->getJson('/api/admin/api-token');

    $response->assertUnauthorized();
});
