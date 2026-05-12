<?php

use App\Models\CnameDelegation;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use Tests\Traits\ActsAsUser;
use Tests\Traits\MocksExternalApis;

uses(ActsAsUser::class, MocksExternalApis::class);

test('获取委托列表', function () {
    $user = User::factory()->create();
    CnameDelegation::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson('/api/delegation')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('获取委托列表-快速搜索', function () {
    $user = User::factory()->create();
    CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'zone' => 'searchme.com',
    ]);

    $this->actingAsUser($user)
        ->getJson('/api/delegation?quickSearch=searchme')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('创建委托', function () {
    $user = User::factory()->create();

    $mockService = Mockery::mock(CnameDelegationService::class);
    $delegation = CnameDelegation::factory()->create(['user_id' => $user->id]);
    $mockService->shouldReceive('createOrGet')->once()->andReturn($delegation);
    $mockService->shouldReceive('withCnameGuide')->once()->andReturn($delegation->toArray());
    app()->instance(CnameDelegationService::class, $mockService);

    $this->actingAsUser($user)
        ->postJson('/api/delegation', [
            'zone' => 'example.com',
            'prefix' => '_dnsauth',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取委托详情', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson("/api/delegation/$delegation->id")
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取委托详情-不存在', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/delegation/99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('删除委托', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->deleteJson("/api/delegation/$delegation->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(CnameDelegation::find($delegation->id))->toBeNull();
});

test('批量创建委托', function () {
    $user = User::factory()->create();

    $mockService = Mockery::mock(CnameDelegationService::class);
    $delegation = CnameDelegation::factory()->create(['user_id' => $user->id]);
    $mockService->shouldReceive('createOrGet')->andReturn($delegation);
    $mockService->shouldReceive('withCnameGuide')->andReturn($delegation->toArray());
    app()->instance(CnameDelegationService::class, $mockService);

    $this->actingAsUser($user)
        ->postJson('/api/delegation/batch-store', [
            'zones' => "example1.com\nexample2.com",
            'prefix' => '_dnsauth',
        ])
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['created', 'failed', 'total', 'success_count', 'fail_count']]);
});

test('手动检查委托', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create(['user_id' => $user->id]);

    $mockService = Mockery::mock(CnameDelegationService::class);
    $mockService->shouldReceive('checkAndUpdateValidity')->once()->andReturn(true);
    $mockService->shouldReceive('checkTxtConflict')->once()->andReturn(null);
    $mockService->shouldReceive('withCnameGuide')->once()->andReturn($delegation->toArray());
    app()->instance(CnameDelegationService::class, $mockService);

    $this->actingAsUser($user)
        ->postJson("/api/delegation/check/$delegation->id")
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('批量删除委托', function () {
    $user = User::factory()->create();
    $delegations = CnameDelegation::factory()->count(3)->create(['user_id' => $user->id]);
    $ids = $delegations->pluck('id')->toArray();

    $this->actingAsUser($user)
        ->deleteJson('/api/delegation/batch', ['ids' => $ids])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(CnameDelegation::whereIn('id', $ids)->count())->toBe(0);
});

test('批量删除委托-只删除当前用户的记录', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // userA 和 userB 各创建委托记录
    $delegationsA = CnameDelegation::factory()->count(2)->create(['user_id' => $userA->id]);
    $delegationsB = CnameDelegation::factory()->count(2)->create(['user_id' => $userB->id]);

    $idsA = $delegationsA->pluck('id')->toArray();

    // userA 删除自己的记录
    $this->actingAsUser($userA)
        ->deleteJson('/api/delegation/batch', ['ids' => $idsA])
        ->assertOk()
        ->assertJson(['code' => 1]);

    // userA 的记录已删除
    expect(CnameDelegation::withoutGlobalScopes()->whereIn('id', $idsA)->count())->toBe(0);

    // userB 的记录不受影响
    $idsB = $delegationsB->pluck('id')->toArray();
    expect(CnameDelegation::withoutGlobalScopes()->whereIn('id', $idsB)->count())->toBe(2);
});

test('批量删除委托-传入其他用户的 ids 不会删除', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // userB 的委托记录
    $delegationsB = CnameDelegation::factory()->count(3)->create(['user_id' => $userB->id]);
    $idsB = $delegationsB->pluck('id')->toArray();

    // userA 尝试删除 userB 的记录 — 应被拒绝
    $this->actingAsUser($userA)
        ->deleteJson('/api/delegation/batch', ['ids' => $idsB])
        ->assertOk()
        ->assertJson(['code' => 0]); // error 返回 code=0

    // userB 的记录全部保留
    expect(CnameDelegation::withoutGlobalScopes()->whereIn('id', $idsB)->count())->toBe(3);
});

test('批量删除委托-混合自己和他人的 ids 只删除自己的', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // 各创建委托记录
    $delegationA = CnameDelegation::factory()->create(['user_id' => $userA->id]);
    $delegationB = CnameDelegation::factory()->create(['user_id' => $userB->id]);

    $mixedIds = [$delegationA->id, $delegationB->id];

    // userA 传入混合 ids
    $this->actingAsUser($userA)
        ->deleteJson('/api/delegation/batch', ['ids' => $mixedIds])
        ->assertOk()
        ->assertJson(['code' => 1]);

    // userA 自己的记录被删除
    expect(CnameDelegation::withoutGlobalScopes()->find($delegationA->id))->toBeNull();

    // userB 的记录仍然存在
    expect(CnameDelegation::withoutGlobalScopes()->find($delegationB->id))->not->toBeNull();
});

test('委托列表-未认证', function () {
    $this->getJson('/api/delegation')
        ->assertUnauthorized();
});
