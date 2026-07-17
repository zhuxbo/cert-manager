<?php

use App\Exceptions\ApiResponseException;
use App\Models\Admin;
use App\Models\Product;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use App\Services\Product\ProductCostNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class, RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

function productCostUpstreamItem(string $code, mixed $cost): array
{
    $item = Product::factory()->make([
        'code' => $code,
        'api_id' => $code,
        'source' => 'cost-source',
        'periods' => [12],
        'alternative_name_types' => ['standard'],
    ])->toArray();
    $item['cost'] = $cost;

    return $item;
}

function bindProductCostImportApi(array $items): void
{
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('getProducts')
        ->once()
        ->with('cost-source', '', '')
        ->andReturn(['code' => 1, 'data' => $items]);
    app()->instance(Api::class, $mock);
}

function runProductCostImport(string $type = 'new', bool $resilient = false): ?ApiResponseException
{
    try {
        app(Action::class)->importProduct('cost-source', '', '', $type, $resilient);
    } catch (ApiResponseException $e) {
        return $e;
    }

    if ($resilient) {
        return null;
    }

    throw new RuntimeException('导入路径应通过 ApiResponseException 返回结果');
}

test('人工保存完整成本保留主价零值和规范化结果', function () {
    $product = Product::factory()->create([
        'periods' => [12],
        'alternative_name_types' => ['standard'],
    ]);

    $this->actingAsAdmin(Admin::factory()->create())
        ->patchJson("/api/admin/product/cost/$product->id", [
            'cost' => [
                'price' => ['12' => '0'],
                'alternative_standard_price' => ['12' => '10.00'],
            ],
        ])->assertJsonPath('code', 1);

    $product->refresh();
    expect($product->getRawOriginal('cost'))->toContain('"price":{"12":"0"}')
        ->and($product->getRawOriginal('cost'))->toContain('"alternative_standard_price":{"12":"10"}');
});

test('人工保存缺少适用 SAN 成本时返回字段级错误且不落零值', function () {
    $product = Product::factory()->create([
        'periods' => [12],
        'alternative_name_types' => ['standard'],
    ]);

    $response = $this->actingAsAdmin(Admin::factory()->create())
        ->patchJson("/api/admin/product/cost/$product->id", [
            'cost' => [
                'price' => ['12' => '100'],
            ],
        ]);

    $response->assertJsonPath('code', 0)
        ->assertJsonPath('msg', '产品成本数据不完整')
        ->assertJsonStructure(['errors' => ['cost.alternative_standard_price.12']]);
    expect($product->fresh()->getRawOriginal('cost'))->toBeNull();
});

test('修改周期后原始成本缺项会在初始化校验时告警', function () {
    $product = Product::factory()->create([
        'periods' => [12],
        'alternative_name_types' => [],
        'cost' => ['price' => ['12' => '100']],
    ]);

    $product->periods = [12, 24];
    $product->save();

    $result = app(ProductCostNormalizer::class)->validateStored($product->fresh());

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toMatchArray(['field' => 'price', 'period' => 24]);
});

test('导入完整数组成本原样保存', function () {
    $cost = [
        'price' => ['12' => '0'],
        'alternative_standard_price' => ['12' => '10.2000'],
    ];
    bindProductCostImportApi([
        productCostUpstreamItem('COST-COMPLETE', $cost),
    ]);

    $response = runProductCostImport();
    $product = Product::where('source', 'cost-source')->where('api_id', 'COST-COMPLETE')->firstOrFail();

    expect($response->getApiResponse()['code'])->toBe(1)
        ->and(json_decode($product->getRawOriginal('cost'), true))->toBe($cost);
});

test('导入数组成本不按产品适用范围校验并原样保存', function () {
    $item = productCostUpstreamItem('COST-RAW', [
        'price' => ['12' => '100.00', '24' => '200.00', '36' => '300.00'],
        'alternative_standard_price' => ['12' => '10.00', '24' => '20.00', '36' => '30.00'],
        'alternative_wildcard_price' => ['12' => '15.00', '24' => '25.00', '36' => '35.00'],
    ]);
    bindProductCostImportApi([$item]);

    $response = runProductCostImport();
    $product = Product::where('source', 'cost-source')->where('api_id', 'COST-RAW')->firstOrFail();

    expect($response->getApiResponse()['code'])->toBe(1)
        ->and(json_decode($product->getRawOriginal('cost'), true))->toBe($item['cost']);
});

test('新建导入忽略非数组成本并继续创建产品', function () {
    bindProductCostImportApi([
        productCostUpstreamItem('COST-BAD-SHAPE-CREATE', 'invalid-cost'),
    ]);

    $response = runProductCostImport();
    $product = Product::where('source', 'cost-source')
        ->where('api_id', 'COST-BAD-SHAPE-CREATE')
        ->firstOrFail();

    expect($response->getApiResponse()['code'])->toBe(1)
        ->and($product->getRawOriginal('cost'))->toBeNull();
});

test('更新导入忽略非数组成本并保留原成本', function () {
    $product = Product::factory()->create([
        'source' => 'cost-source',
        'api_id' => 'COST-BAD-SHAPE-UPDATE',
        'cost' => ['price' => ['12' => '88']],
    ]);
    bindProductCostImportApi([
        productCostUpstreamItem('COST-BAD-SHAPE-UPDATE', 'invalid-cost'),
    ]);

    $response = runProductCostImport('update');

    expect($response->getApiResponse()['code'])->toBe(1)
        ->and(json_decode($product->fresh()->getRawOriginal('cost'), true))
        ->toBe(['price' => ['12' => '88']]);
});

test('导入缺少适用 SAN 成本时仍成功并原样保存', function () {
    $cost = [
        'price' => ['12' => '100'],
    ];
    bindProductCostImportApi([
        productCostUpstreamItem('COST-INCOMPLETE', $cost),
    ]);

    $response = runProductCostImport();
    $product = Product::where('source', 'cost-source')
        ->where('api_id', 'COST-INCOMPLETE')
        ->firstOrFail();

    expect($response->getApiResponse()['code'])->toBe(1)
        ->and(json_decode($product->getRawOriginal('cost'), true))->toBe($cost);
});

test('resilient 导入原样保存内层畸形的数组成本且不记录失败', function () {
    $cost = [
        'price' => '100',
        'alternative_standard_price' => ['12' => '10'],
    ];
    bindProductCostImportApi([
        productCostUpstreamItem('COST-BAD-SHAPE', $cost),
    ]);

    $action = app(Action::class);
    $action->importProduct('cost-source', '', '', 'new', true);

    $product = Product::where('source', 'cost-source')
        ->where('api_id', 'COST-BAD-SHAPE')
        ->firstOrFail();

    expect($action->getImportIssues())->toBe([])
        ->and(json_decode($product->getRawOriginal('cost'), true))->toBe($cost);
});

test('导入更新形状并提供数组成本时保留原始 JSON', function () {
    $product = Product::factory()->create([
        'source' => 'cost-source',
        'api_id' => 'COST-SAME',
        'periods' => [12],
        'alternative_name_types' => ['standard'],
        'cost' => [
            'price' => ['12' => '0'],
            'alternative_standard_price' => ['12' => '10'],
        ],
    ]);
    $item = productCostUpstreamItem('COST-SAME', [
        'price' => ['12' => '0'],
        'alternative_standard_price' => ['12' => '10'],
    ]);
    $item['alternative_name_types'] = ['standard', 'ipv4'];
    bindProductCostImportApi([$item]);

    $response = runProductCostImport('update');

    expect($response?->getApiResponse()['code'])->toBe(1)
        ->and($product->fresh()->alternative_name_types)->toBe(['standard', 'ipv4'])
        ->and($product->fresh()->getRawOriginal('cost'))
        ->toContain('"alternative_standard_price":{"12":"10"}');
});

test('update 不存在产品时跳过畸形 cost 而不报错', function () {
    bindProductCostImportApi([
        productCostUpstreamItem('COST-SKIP-UPDATE', 'invalid-cost'),
    ]);

    $response = runProductCostImport('update');

    expect($response?->getApiResponse()['code'])->toBe(1)
        ->and(Product::where('source', 'cost-source')->where('api_id', 'COST-SKIP-UPDATE')->exists())
        ->toBeFalse();
});

test('new 已存在产品时跳过畸形 cost 而不报错', function () {
    Product::factory()->create([
        'source' => 'cost-source',
        'api_id' => 'COST-SKIP-NEW',
    ]);
    bindProductCostImportApi([
        productCostUpstreamItem('COST-SKIP-NEW', 'invalid-cost'),
    ]);

    $response = runProductCostImport('new');

    expect($response?->getApiResponse()['code'])->toBe(1)
        ->and(Product::where('source', 'cost-source')->where('api_id', 'COST-SKIP-NEW')->count())
        ->toBe(1);
});
