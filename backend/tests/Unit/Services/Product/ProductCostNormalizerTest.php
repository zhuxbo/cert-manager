<?php

use App\Models\Product;
use App\Services\Product\ProductCostNormalizer;
use Tests\TestCase;

uses(TestCase::class);

function costProduct(array $attributes = []): Product
{
    return Product::factory()->make($attributes + [
        'periods' => [12],
        'alternative_name_types' => [],
    ]);
}

test('保留明确零主价并把金额规范为十进制字符串', function () {
    $product = costProduct([
        'alternative_name_types' => ['standard', 'wildcard'],
    ]);

    $result = app(ProductCostNormalizer::class)->normalize($product, [
        'price' => ['12' => 0],
        'alternative_standard_price' => ['12' => 10.2],
        'alternative_wildcard_price' => ['12' => '20.3000'],
    ]);

    expect($result['warnings'])->toBeEmpty()
        ->and($result['cost']['price']['12'])->toBe('0')
        ->and($result['cost']['alternative_standard_price']['12'])->toBe('10.2')
        ->and($result['cost']['alternative_wildcard_price']['12'])->toBe('20.3');
});

test('适用 SAN 成本缺失或为零会告警', function () {
    $product = costProduct([
        'alternative_name_types' => ['standard', 'wildcard'],
    ]);

    $result = app(ProductCostNormalizer::class)->normalize($product, [
        'price' => ['12' => '100'],
        'alternative_standard_price' => ['12' => '0'],
    ]);

    expect(collect($result['warnings'])->pluck('field')->all())
        ->toEqualCanonicalizing([
            'alternative_standard_price',
            'alternative_wildcard_price',
        ]);
});

test('纯主价产品的零价格会告警', function () {
    $result = app(ProductCostNormalizer::class)->normalize(costProduct(), [
        'price' => ['12' => 0],
    ]);

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['field'])->toBe('price')
        ->and($result['warnings'][0]['period'])->toBe(12);
});

test('每个适用周期都必须有成本 key', function () {
    $product = costProduct(['periods' => [12, 24]]);

    $result = app(ProductCostNormalizer::class)->normalize($product, [
        'price' => ['12' => '100'],
    ]);

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['field'])->toBe('price')
        ->and($result['warnings'][0]['period'])->toBe(24);
});

test('负数科学计数法和非数字金额都会告警', function (mixed $value) {
    $result = app(ProductCostNormalizer::class)->normalize(costProduct(), [
        'price' => ['12' => $value],
    ]);

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['field'])->toBe('price')
        ->and($result['cost']['price'] ?? [])->not->toHaveKey('12');
})->with([
    '负数' => '-1',
    '科学计数法' => '1e3',
    '非数字' => 'abc',
]);

test('额外周期 key 会告警且不会进入规范化结果', function () {
    $result = app(ProductCostNormalizer::class)->normalize(costProduct(), [
        'price' => ['12' => '100', '24' => '200'],
    ]);

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0]['field'])->toBe('price')
        ->and($result['warnings'][0]['period'])->toBe(24)
        ->and($result['cost']['price'])->toBe(['12' => '100']);
});

test('内层成本不是数组时返回结构化告警', function () {
    $result = app(ProductCostNormalizer::class)->normalize(costProduct(), [
        'price' => '100',
    ]);

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toMatchArray([
            'field' => 'price',
            'period' => 12,
        ]);
});

test('需要科学计数法表示的 float 不会静默舍入为零', function () {
    $product = costProduct([
        'alternative_name_types' => ['standard'],
    ]);

    $result = app(ProductCostNormalizer::class)->normalize($product, [
        'price' => ['12' => 0.00000000001],
        'alternative_standard_price' => ['12' => '10'],
    ]);

    expect(collect($result['warnings'])->pluck('field')->all())->toContain('price')
        ->and($result['cost']['price'] ?? [])->not->toHaveKey('12');
});

test('validateStored 只从原始 JSON 读取成本并按当前结构校验', function () {
    $product = costProduct();
    $product->setRawAttributes(array_merge($product->getAttributes(), [
        'cost' => '{"price":{"12":0}}',
    ]), true);

    $result = app(ProductCostNormalizer::class)->validateStored($product);

    expect($result['cost']['price']['12'])->toBe('0')
        ->and(collect($result['warnings'])->pluck('field')->all())->toBe(['price']);
});

test('周期变更后 validateStored 会按当前周期报告原始 JSON 缺项', function () {
    $product = costProduct(['periods' => [12, 24]]);
    $product->setRawAttributes(array_merge($product->getAttributes(), [
        'cost' => '{"price":{"12":"100"}}',
    ]), true);

    $result = app(ProductCostNormalizer::class)->validateStored($product);

    expect($result['cost'])->toBe(['price' => ['12' => '100']])
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toMatchArray([
            'field' => 'price',
            'period' => 24,
        ]);
});

test('SAN 类型变更后 validateStored 会报告新适用成本缺失', function () {
    $product = costProduct(['alternative_name_types' => ['standard']]);
    $product->setRawAttributes(array_merge($product->getAttributes(), [
        'cost' => '{"price":{"12":"0"}}',
    ]), true);

    $result = app(ProductCostNormalizer::class)->validateStored($product);

    expect(collect($result['warnings'])->pluck('field')->all())
        ->toBe(['alternative_standard_price']);
});
