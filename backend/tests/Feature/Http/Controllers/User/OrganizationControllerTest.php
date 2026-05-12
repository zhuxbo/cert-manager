<?php

use App\Models\Organization;
use App\Models\User;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('获取组织列表', function () {
    $user = User::factory()->create();
    Organization::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson('/api/organization')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('获取组织列表-快速搜索', function () {
    $user = User::factory()->create();
    Organization::factory()->create([
        'user_id' => $user->id,
        'name' => 'TestOrg',
    ]);

    $this->actingAsUser($user)
        ->getJson('/api/organization?quickSearch=TestOrg')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('创建组织', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/organization', [
            'name' => 'Test Organization',
            'registration_number' => '1234567890',
            'country' => 'CN',
            'state' => 'Shanghai',
            'city' => 'Shanghai',
            'address' => 'Test Address',
            'postcode' => '200000',
            'phone' => '021-12345678',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Organization::where('user_id', $user->id)->count())->toBe(1);
});

test('获取组织详情', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson("/api/organization/$org->id")
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['id', 'name', 'registration_number', 'country']]);
});

test('获取组织详情-不存在', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/organization/99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('更新组织', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->putJson("/api/organization/$org->id", [
            'name' => 'Updated Organization',
            'registration_number' => '9876543210',
            'country' => 'CN',
            'state' => 'Beijing',
            'city' => 'Beijing',
            'address' => 'Test Address',
            'postcode' => '100000',
            'phone' => '010-12345678',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($org->fresh()->name)->toBe('Updated Organization');
});

test('删除组织', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->deleteJson("/api/organization/$org->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Organization::find($org->id))->toBeNull();
});

test('批量获取组织', function () {
    $user = User::factory()->create();
    $orgs = Organization::factory()->count(3)->create(['user_id' => $user->id]);
    $ids = $orgs->pluck('id')->toArray();

    $this->actingAsUser($user)
        ->getJson('/api/organization/batch?ids='.implode(',', $ids))
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('批量删除组织', function () {
    $user = User::factory()->create();
    $orgs = Organization::factory()->count(3)->create(['user_id' => $user->id]);
    $ids = $orgs->pluck('id')->toArray();

    $this->actingAsUser($user)
        ->deleteJson('/api/organization/batch', ['ids' => $ids])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Organization::whereIn('id', $ids)->count())->toBe(0);
});

test('组织列表-未认证', function () {
    $this->getJson('/api/organization')
        ->assertUnauthorized();
});
