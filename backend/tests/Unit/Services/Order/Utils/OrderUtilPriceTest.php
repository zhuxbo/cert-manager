<?php

use App\Models\ProductPrice;
use App\Services\Order\Utils\OrderUtil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

// A4 零价成单守卫：hasPriceConfigured 以「价格行存在性」区分「显式免费（行在 price=0）」与「缺价（无行）」。
// getMinPrice 内部抽 fetchPriceRows，行为须逐字不变（缺价仍返 '0'）。这些方法查 ProductPrice + FindUtil::User，需 DB。
uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

test('hasPriceConfigured：存在 level_code 价格行 → true', function () {
    $user = $this->createTestUser(['level_code' => 'standard']);
    $product = $this->createTestProduct();
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12, 'price' => '100.00',
    ]);

    expect(OrderUtil::hasPriceConfigured($user->id, $product->id, 12))->toBeTrue();
});

test('hasPriceConfigured：仅存在 custom_level_code 价格行 → true', function () {
    $user = $this->createTestUser(['level_code' => 'standard', 'custom_level_code' => 'vip']);
    $product = $this->createTestProduct();
    // 只建 custom 级价格行，standard 级无
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'vip', 'period' => 12, 'price' => '80.00',
    ]);

    expect(OrderUtil::hasPriceConfigured($user->id, $product->id, 12))->toBeTrue();
});

test('hasPriceConfigured：level 与 custom 皆无价格行 → false（缺价）', function () {
    $user = $this->createTestUser(['level_code' => 'standard', 'custom_level_code' => 'vip']);
    $product = $this->createTestProduct();
    // 不建任何价格行

    expect(OrderUtil::hasPriceConfigured($user->id, $product->id, 12))->toBeFalse();
});

test('hasPriceConfigured：显式免费产品（行存在 price=0.00）→ true（不误伤真 0 元）', function () {
    $user = $this->createTestUser(['level_code' => 'standard']);
    $product = $this->createTestProduct();
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12, 'price' => '0.00',
    ]);

    expect(OrderUtil::hasPriceConfigured($user->id, $product->id, 12))->toBeTrue();
});

test('hasPriceConfigured：其他 period 有行但目标 period 无行 → false', function () {
    $user = $this->createTestUser(['level_code' => 'standard']);
    $product = $this->createTestProduct();
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 24, 'price' => '100.00',
    ]);

    expect(OrderUtil::hasPriceConfigured($user->id, $product->id, 12))->toBeFalse();
});

test('getMinPrice 抽取 fetchPriceRows 后行为不变：缺价返 0', function () {
    $user = $this->createTestUser(['level_code' => 'standard']);
    $product = $this->createTestProduct();

    $minPrice = OrderUtil::getMinPrice($user->id, $product->id, 12);
    expect($minPrice['price'])->toBe('0')
        ->and($minPrice['alternative_standard_price'])->toBe('0')
        ->and($minPrice['alternative_wildcard_price'])->toBe('0');
});

test('getMinPrice 抽取 fetchPriceRows 后行为不变：有价返实际价', function () {
    $user = $this->createTestUser(['level_code' => 'standard']);
    $product = $this->createTestProduct();
    ProductPrice::create([
        'product_id' => $product->id, 'level_code' => 'standard', 'period' => 12,
        'price' => '123.45', 'alternative_standard_price' => '10.00', 'alternative_wildcard_price' => '20.00',
    ]);

    $minPrice = OrderUtil::getMinPrice($user->id, $product->id, 12);
    expect($minPrice['price'])->toBe('123.45')
        ->and($minPrice['alternative_standard_price'])->toBe('10.00')
        ->and($minPrice['alternative_wildcard_price'])->toBe('20.00');
});
