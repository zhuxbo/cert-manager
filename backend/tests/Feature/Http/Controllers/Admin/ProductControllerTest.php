<?php

use App\Models\Admin;
use App\Models\Product;
use App\Services\Order\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;
use Tests\Traits\MocksExternalApis;

uses(ActsAsAdmin::class);
uses(MocksExternalApis::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('管理员可以获取产品列表', function () {
    Product::factory()->count(3)->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以快速搜索产品', function () {
    Product::factory()->create(['name' => 'RapidSSL Standard']);
    Product::factory()->create(['name' => 'Other Product']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product?quickSearch=RapidSSL');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按品牌筛选产品', function () {
    Product::factory()->create(['brand' => 'Sectigo']);
    Product::factory()->create(['brand' => 'DigiCert']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product?brand=Sectigo');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按验证类型筛选产品', function () {
    Product::factory()->create(['validation_type' => 'dv']);
    Product::factory()->create(['validation_type' => 'ov']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product?validation_type=ov');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看产品详情', function () {
    $product = Product::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/product/$product->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $product->id);
});

test('查看不存在的产品返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以添加产品', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/product', [
        'code' => 'test_product',
        'name' => 'Test Product',
        'api_id' => 'api_test_123',
        'source' => 'test',
        'product_type' => 'ssl',
        'brand' => 'TestBrand',
        'ca' => 'TestCA',
        'validation_type' => 'dv',
        'common_name_types' => ['standard'],
        'alternative_name_types' => [],
        'validation_methods' => ['txt'],
        'periods' => [12],
        'encryption_standard' => 'international',
        'encryption_alg' => ['rsa'],
        'signature_digest_alg' => ['sha256'],
        'warranty_currency' => '$',
        'warranty' => 10000,
        'server' => 1,
        'standard_min' => 0,
        'standard_max' => 100,
        'wildcard_min' => 0,
        'wildcard_max' => 0,
        'total_min' => 1,
        'total_max' => 100,
        'add_san' => 1,
        'replace_san' => 1,
        'reissue' => 1,
        'renew' => 1,
        'reuse_csr' => 1,
        'gift_root_domain' => 0,
        'refund_period' => 30,
        'weight' => 0,
        'status' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以更新产品信息', function () {
    $product = Product::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/product/$product->id", [
        'name' => 'Updated Product Name',
        'status' => 0,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $product->refresh();
    expect($product->name)->toBe('Updated Product Name');
});

test('管理员可以删除产品', function () {
    $product = Product::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/product/$product->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Product::find($product->id))->toBeNull();
});

test('管理员可以批量删除产品', function () {
    $products = Product::factory()->count(3)->create();
    $ids = $products->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/product/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Product::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以导入产品', function () {
    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('importProduct')->once();
    $this->app->instance(Action::class, $mockAction);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/product/import', [
        'source' => 'test',
        'brand' => 'TestBrand',
        'type' => 'new',
    ]);

    $response->assertOk();
});

test('管理员可以获取产品成本信息', function () {
    $product = Product::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/product/cost/$product->id");

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以获取来源列表', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product/source');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理员可以按状态筛选产品', function () {
    Product::factory()->create(['status' => 1]);
    Product::factory()->create(['status' => 0]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product?status=1');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按加密标准筛选产品', function () {
    Product::factory()->create(['encryption_standard' => 'international']);
    Product::factory()->create(['encryption_standard' => 'chinese']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/product?encryption_standard=chinese');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('未认证用户无法访问产品管理', function () {
    $response = $this->getJson('/api/admin/product');

    $response->assertUnauthorized();
});
