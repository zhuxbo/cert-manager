<?php

use App\Models\Admin;
use App\Models\Chain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取证书链列表', function () {
    Chain::factory()->count(3)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/chain');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以按名称搜索证书链', function () {
    Chain::factory()->create(['common_name' => 'Sectigo RSA CA']);
    Chain::factory()->create(['common_name' => 'DigiCert SHA2 CA']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/chain?common_name=Sectigo');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看证书链详情', function () {
    $chain = Chain::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/chain/$chain->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $chain->id);
});

test('查看不存在的证书链返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/chain/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以添加证书链', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/chain', [
        'common_name' => 'Test CA Intermediate',
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----",
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Chain::where('common_name', 'Test CA Intermediate')->exists())->toBeTrue();
});

test('管理员可以更新证书链', function () {
    $chain = Chain::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/chain/$chain->id", [
        'common_name' => 'Updated CA Name',
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nUPDATED\n-----END CERTIFICATE-----",
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $chain->refresh();
    expect($chain->common_name)->toBe('Updated CA Name');
});

test('管理员可以删除证书链', function () {
    $chain = Chain::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/chain/$chain->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Chain::find($chain->id))->toBeNull();
});

test('管理员可以批量删除证书链', function () {
    $chains = Chain::factory()->count(3)->create();
    $ids = $chains->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/chain/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Chain::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以批量获取证书链', function () {
    $chains = Chain::factory()->count(3)->create();
    $ids = $chains->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/chain/batch?ids[]='.implode('&ids[]=', $ids));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以分页获取证书链', function () {
    Chain::factory()->count(15)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/chain?currentPage=2&pageSize=5');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.currentPage', 2);
    $response->assertJsonPath('data.pageSize', 5);
    expect($response->json('data.total'))->toBe(15);
    expect($response->json('data.items'))->toHaveCount(5);
});

test('未认证用户无法访问证书链管理', function () {
    $response = $this->getJson('/api/admin/chain');

    $response->assertUnauthorized();
});
