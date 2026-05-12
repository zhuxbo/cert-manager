<?php

use App\Models\Admin;
use App\Models\CnameDelegation;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员可以获取委托列表', function () {
    CnameDelegation::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/delegation');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以快速搜索委托', function () {
    CnameDelegation::factory()->create(['user_id' => $this->user->id, 'zone' => 'special.com']);
    CnameDelegation::factory()->create(['user_id' => $this->user->id, 'zone' => 'other.com']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/delegation?quickSearch=special');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按用户ID筛选委托', function () {
    CnameDelegation::factory()->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    CnameDelegation::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/delegation?user_id={$this->user->id}");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按有效状态筛选委托', function () {
    CnameDelegation::factory()->verified()->create(['user_id' => $this->user->id]);
    CnameDelegation::factory()->invalid()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/delegation?valid=1');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看委托详情', function () {
    $delegation = CnameDelegation::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/delegation/$delegation->id");

    $response->assertOk()->assertJson(['code' => 1]);
});

test('查看不存在的委托返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/delegation/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以创建委托', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/delegation', [
        'user_id' => $this->user->id,
        'zone' => 'test.com',
        'prefix' => '_dnsauth',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以删除委托', function () {
    $delegation = CnameDelegation::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/delegation/$delegation->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(CnameDelegation::find($delegation->id))->toBeNull();
});

test('管理员可以批量删除委托', function () {
    $delegations = CnameDelegation::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $delegations->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/delegation/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(CnameDelegation::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以批量创建委托', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/delegation/batch-store', [
        'user_id' => $this->user->id,
        'zones' => "domain1.com\ndomain2.com",
        'prefix' => '_dnsauth',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['created', 'failed', 'total', 'success_count', 'fail_count']]);
});

test('管理员可以手动检查委托健康状态', function () {
    $delegation = CnameDelegation::factory()->create(['user_id' => $this->user->id]);

    $mock = Mockery::mock(CnameDelegationService::class);
    $mock->shouldReceive('checkAndUpdateValidity')->andReturn(true);
    $mock->shouldReceive('checkTxtConflict')->andReturn(null);
    $mock->shouldReceive('withCnameGuide')->andReturn($delegation->toArray());
    $this->app->instance(CnameDelegationService::class, $mock);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/delegation/check/$delegation->id");

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以批量获取委托', function () {
    $delegations = CnameDelegation::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $delegations->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/delegation/batch?ids[]='.implode('&ids[]=', $ids));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('未认证用户无法访问委托管理', function () {
    $response = $this->getJson('/api/admin/delegation');

    $response->assertUnauthorized();
});
