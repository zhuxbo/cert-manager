<?php

use App\Models\Admin;
use App\Models\CnameDelegation;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Delegation\DelegationConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

function configureAdminDelegationProxyDomain(): void
{
    $configService = app(DelegationConfigService::class);
    $domain = 'proxy.example.com';
    $group = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '委托设置', 'description' => null, 'weight' => 1],
    );

    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'delegationDomain'],
        [
            'type' => 'string',
            'options' => null,
            'is_multiple' => false,
            'value' => $domain,
            'description' => '默认代理域',
            'weight' => 1,
        ],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => $configService->keyForDomain($domain)],
        [
            'type' => 'array',
            'options' => null,
            'is_multiple' => false,
            'value' => [
                'domain' => $domain,
                'provider' => 'cloudflare',
                'apiToken' => 'test-token',
                'zoneId' => 'test-zone',
            ],
            'description' => '测试委托代理域',
            'weight' => 2,
        ],
    );
    Setting::setValue('delegation', 'delegationDomain', $domain);
}

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
    configureAdminDelegationProxyDomain();
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

test('管理员委托响应只暴露 proxy_domain', function () {
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $this->user->id,
        'proxy_domain' => 'proxy.example.com',
    ]);

    $data = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/delegation/$delegation->id")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('proxy_domain', 'proxy.example.com')
        ->not->toHaveKey('proxy_zone');
});

test('查看不存在的委托返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/delegation/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以创建委托', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/delegation', [
        'user_id' => $this->user->id,
        'zone' => 'test.com',
        'ca' => 'digicert',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    // 入参按 ca 选择，内部派生 prefix=_dnsauth
    expect(CnameDelegation::withoutGlobalScopes()->where([
        'user_id' => $this->user->id,
        'zone' => 'test.com',
        'prefix' => '_dnsauth',
    ])->exists())->toBeTrue();
});

test('管理员手工创建已有委托时保留已检测域并保持 ID', function () {
    $existing = CnameDelegation::factory()->create([
        'user_id' => $this->user->id,
        'zone' => 'test.com',
        'prefix' => '_dnsauth',
        'proxy_domain' => 'old-proxy.example.com',
    ]);

    $this->actingAsAdmin($this->admin)->postJson('/api/admin/delegation', [
        'user_id' => $this->user->id,
        'zone' => 'test.com',
        'ca' => 'digicert',
    ])->assertOk()->assertJson(['code' => 1]);

    expect($existing->fresh()->proxy_domain)->toBe('old-proxy.example.com')
        ->and(CnameDelegation::withoutGlobalScopes()->where([
            'user_id' => $this->user->id,
            'zone' => 'test.com',
            'prefix' => '_dnsauth',
        ])->count())->toBe(1);
});

test('管理员创建委托按 ca 派生 prefix 与 zone', function () {
    // sectigo → _pki-validation，子域取根域
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/delegation', [
        'user_id' => $this->user->id,
        'zone' => 'sub.example.com',
        'ca' => 'sectigo',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    expect(CnameDelegation::withoutGlobalScopes()->where([
        'user_id' => $this->user->id,
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ])->exists())->toBeTrue();
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
        'ca' => 'digicert',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['created', 'failed', 'total', 'success_count', 'fail_count']]);

    // 按 ca 派生 prefix=_dnsauth
    expect(CnameDelegation::withoutGlobalScopes()->where([
        'user_id' => $this->user->id,
        'prefix' => '_dnsauth',
    ])->count())->toBe(2);
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
