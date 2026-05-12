<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;
use Tests\Traits\MocksExternalApis;

uses(ActsAsAdmin::class);
uses(MocksExternalApis::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    UserLevel::factory()->standard()->create();
});

test('管理员可以获取用户列表', function () {
    User::factory()->count(3)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/user');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以通过快速搜索筛选用户', function () {
    User::factory()->create(['username' => 'searchme']);
    User::factory()->create(['username' => 'other']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/user?quickSearch=searchme');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按状态筛选用户', function () {
    User::factory()->count(2)->create(['status' => 1]);
    User::factory()->disabled()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/user?status=0');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看用户详情', function () {
    $user = User::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/user/$user->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $user->id);
});

test('查看不存在的用户返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/user/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以添加用户', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/user', [
        'username' => 'newuser',
        'email' => 'newuser@test.com',
        'password' => 'password123',
        'level_code' => 'standard',
        'status' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(User::where('username', 'newuser')->exists())->toBeTrue();
});

test('管理员可以更新用户信息', function () {
    $user = User::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/user/$user->id", [
        'username' => $user->username,
        'email' => 'updated@test.com',
        'level_code' => 'standard',
        'status' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $user->refresh();
    expect($user->email)->toBe('updated@test.com');
});

test('管理员可以删除用户', function () {
    $user = User::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/user/$user->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(User::find($user->id))->toBeNull();
});

test('管理员可以批量删除用户', function () {
    $users = User::factory()->count(3)->create();
    $ids = $users->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/user/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(User::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以直接登录用户', function () {
    $user = User::factory()->create();
    // 需要设置站点 URL
    $group = SettingGroup::factory()->create(['name' => 'site']);
    Setting::factory()->create([
        'group_id' => $group->id,
        'key' => 'url',
        'value' => 'https://example.com',
        'type' => 'string',
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/user/direct-login', [
        'user_id' => $user->id,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['access_token', 'direct_login_url']]);
});

test('管理员可以创建用户并发送通知', function () {
    $this->mockSmtp();

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/user/create-user', [
        'email' => 'created@test.com',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(User::where('email', 'created@test.com')->exists())->toBeTrue();
});

test('管理员可以按用户名精确搜索', function () {
    $user = User::factory()->create(['username' => 'exactuser']);
    User::factory()->count(2)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/user?username=exactuser');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以分页获取用户列表', function () {
    User::factory()->count(15)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/user?currentPage=2&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 2);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBe(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问用户管理', function () {
    $response = $this->getJson('/api/admin/user');

    $response->assertUnauthorized();
});
