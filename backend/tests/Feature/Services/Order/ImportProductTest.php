<?php

use App\Exceptions\ApiResponseException;
use App\Models\Product;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;

afterEach(function () {
    Mockery::close();
});

function bindManualProductImportApi(array $items): void
{
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('getProducts')
        ->once()
        ->with('manual-import', '', '')
        ->andReturn(['code' => 1, 'data' => $items]);
    app()->instance(Api::class, $mock);
}

function runManualProductImport(string $type = 'update'): array
{
    try {
        app(Action::class)->importProduct('manual-import', '', '', $type);
        test()->fail('期望人工导入通过 ApiResponseException 返回结果');
    } catch (ApiResponseException $e) {
        return $e->getApiResponse();
    }

    return [];
}

test('人工更新导入不会创建上游新增产品', function () {
    Product::factory()->create(['source' => 'manual-import', 'api_id' => 'EXISTING']);
    bindManualProductImportApi([['code' => 'NEW-PRODUCT', 'weight' => 3]]);

    expect(runManualProductImport())->toMatchArray(['code' => 1])
        ->and(Product::where('source', 'manual-import')->where('api_id', 'NEW-PRODUCT')->exists())->toBeFalse();
});

test('人工导入遇到缺少 code 的脏产品会立即返回错误', function () {
    bindManualProductImportApi([[]]);

    expect(runManualProductImport())->toMatchArray([
        'code' => 0,
        'msg' => '产品 code 不能为空',
        'errors' => [
            'code' => '',
            'source' => 'manual-import',
        ],
    ]);
});

test('人工 new 导入不会改写已存在产品', function () {
    $product = Product::factory()->create([
        'source' => 'manual-import',
        'api_id' => 'EXISTING-NEW',
        'status' => 1,
    ]);
    bindManualProductImportApi([[
        'code' => 'EXISTING-NEW',
        'status' => 0,
    ]]);

    expect(runManualProductImport('new'))->toMatchArray(['code' => 1])
        ->and($product->fresh()->status)->toBe(1);
});

test('人工 all 导入同时更新已有产品并创建新产品', function () {
    $existing = Product::factory()->create([
        'source' => 'manual-import',
        'api_id' => 'EXISTING-ALL',
        'status' => 1,
    ]);
    bindManualProductImportApi([
        ['code' => 'EXISTING-ALL', 'status' => 0],
        [
            'code' => 'NEW-ALL',
            'brand' => 'sectigo',
            'ca' => 'Sectigo',
            'product_type' => Product::TYPE_SSL,
            'validation_type' => 'dv',
            'periods' => [12],
        ],
    ]);

    expect(runManualProductImport('all'))->toMatchArray(['code' => 1])
        ->and($existing->fresh()->status)->toBe(0)
        ->and(Product::where('source', 'manual-import')->where('api_id', 'NEW-ALL')->exists())->toBeTrue();
});

test('人工更新导入保留本地展示字段和 delegation 验证方式', function () {
    $product = Product::factory()->create([
        'source' => 'manual-import',
        'api_id' => 'PRESERVE-LOCAL',
        'name' => '本地名',
        'remark' => '本地备注',
        'weight' => 5,
        'validation_methods' => ['dns', 'delegation'],
    ]);
    bindManualProductImportApi([[
        'code' => 'PRESERVE-LOCAL',
        'name' => '上游名',
        'remark' => '上游备注',
        'weight' => 99,
        'validation_methods' => ['email'],
    ]]);

    expect(runManualProductImport())->toMatchArray(['code' => 1]);

    $product->refresh();
    expect($product->name)->toBe('本地名')
        ->and($product->remark)->toBe('本地备注')
        ->and($product->weight)->toBe(5)
        ->and($product->validation_methods)->toBe(['email', 'delegation']);
});

test('人工更新导入会填充本地未设置的展示字段', function () {
    $product = Product::factory()->create([
        'source' => 'manual-import',
        'api_id' => 'FILL-LOCAL',
        'name' => '',
        'remark' => null,
        'weight' => 0,
    ]);
    bindManualProductImportApi([[
        'code' => 'FILL-LOCAL',
        'name' => '上游名',
        'remark' => '上游备注',
        'weight' => 9,
    ]]);

    expect(runManualProductImport())->toMatchArray(['code' => 1]);

    $product->refresh();
    expect($product->name)->toBe('上游名')
        ->and($product->remark)->toBe('上游备注')
        ->and($product->weight)->toBe(9);
});

test('人工更新导入过滤 null 字段并保留本地值', function () {
    $product = Product::factory()->create([
        'source' => 'manual-import',
        'api_id' => 'PRESERVE-NULL',
        'brand' => 'sectigo',
        'status' => 1,
    ]);
    bindManualProductImportApi([[
        'code' => 'PRESERVE-NULL',
        'brand' => null,
        'status' => null,
    ]]);

    expect(runManualProductImport())->toMatchArray(['code' => 1]);

    expect($product->fresh()->brand)->toBe('sectigo')
        ->and($product->fresh()->status)->toBe(1);
});

test('人工更新导入执行完整请求校验并拒绝非法周期', function () {
    $product = Product::factory()->create([
        'source' => 'manual-import',
        'api_id' => 'INVALID-PERIOD',
        'periods' => [12],
    ]);
    bindManualProductImportApi([[
        'code' => 'INVALID-PERIOD',
        'periods' => [2],
    ]]);

    expect(runManualProductImport())->toMatchArray([
        'code' => 0,
        'msg' => '产品数据验证失败',
    ])->and($product->fresh()->periods)->toBe([12]);
});

test('人工更新导入跳过不完整的上游域名数量校验', function () {
    $product = Product::factory()->create([
        'source' => 'manual-import',
        'api_id' => 'DOMAIN-LIMITS',
        'standard_min' => 0,
        'standard_max' => 1,
    ]);
    bindManualProductImportApi([[
        'code' => 'DOMAIN-LIMITS',
        'standard_min' => 2,
        'standard_max' => 0,
    ]]);

    expect(runManualProductImport())->toMatchArray(['code' => 1])
        ->and($product->fresh()->standard_min)->toBe(2)
        ->and($product->fresh()->standard_max)->toBe(0);
});
