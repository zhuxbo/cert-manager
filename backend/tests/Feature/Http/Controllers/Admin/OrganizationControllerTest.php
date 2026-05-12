<?php

use App\Models\Admin;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员可以获取组织列表', function () {
    Organization::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/organization');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以快速搜索组织', function () {
    Organization::factory()->create(['user_id' => $this->user->id, 'name' => 'Special Corp']);
    Organization::factory()->create(['user_id' => $this->user->id, 'name' => 'Other LLC']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/organization?quickSearch=Special');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按用户ID筛选组织', function () {
    Organization::factory()->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    Organization::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/organization?user_id={$this->user->id}");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按名称搜索组织', function () {
    Organization::factory()->create(['user_id' => $this->user->id, 'name' => 'Test Company']);
    Organization::factory()->create(['user_id' => $this->user->id, 'name' => 'Other Inc']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/organization?name=Test');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看组织详情', function () {
    $organization = Organization::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/organization/$organization->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $organization->id);
});

test('查看不存在的组织返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/organization/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以添加组织', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/organization', [
        'user_id' => $this->user->id,
        'name' => 'New Organization',
        'registration_number' => '91110000100000001A',
        'country' => 'CN',
        'state' => 'Beijing',
        'city' => 'Beijing',
        'address' => 'Test Address',
        'postcode' => '100000',
        'phone' => '010-12345678',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Organization::where('name', 'New Organization')->exists())->toBeTrue();
});

test('管理员可以更新组织信息', function () {
    $organization = Organization::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/organization/$organization->id", [
        'user_id' => $this->user->id,
        'name' => 'Updated Organization',
        'registration_number' => $organization->registration_number,
        'country' => $organization->country,
        'state' => $organization->state,
        'city' => $organization->city,
        'address' => $organization->address,
        'postcode' => $organization->postcode,
        'phone' => $organization->phone,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $organization->refresh();
    expect($organization->name)->toBe('Updated Organization');
});

test('管理员可以删除组织', function () {
    $organization = Organization::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/organization/$organization->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Organization::find($organization->id))->toBeNull();
});

test('管理员可以批量删除组织', function () {
    $organizations = Organization::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $organizations->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/organization/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Organization::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以批量获取组织', function () {
    $organizations = Organization::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $organizations->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/organization/batch?ids[]='.implode('&ids[]=', $ids));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('未认证用户无法访问组织管理', function () {
    $response = $this->getJson('/api/admin/organization');

    $response->assertUnauthorized();
});
