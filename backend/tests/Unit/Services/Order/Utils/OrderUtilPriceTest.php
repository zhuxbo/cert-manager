<?php

use App\Models\Acme;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\Order\Utils\OrderUtil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;
use Tymon\JWTAuth\Facades\JWTAuth;

// A4 零价成单守卫：hasPriceConfigured 以「价格行存在性」区分「显式免费（行在 price=0）」与「缺价（无行）」。
// getMinPrice 内部抽 fetchPriceRows，行为须逐字不变（缺价仍返 '0'）。这些方法查 ProductPrice + FindUtil::User，需 DB。
uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

function initializeTaskFourOrderPrices($test, array $productAttributes = []): array
{
    $admin = Admin::factory()->create();
    $level = UserLevel::factory()->create([
        'code' => 'task-four-order-level',
        'name' => 'Task Four Order Level',
        'cost_rate' => '1.0000',
    ]);
    $user = User::factory()->create(['level_code' => $level->code]);
    $product = Product::factory()->create(array_replace([
        'periods' => [12],
        'alternative_name_types' => ['standard', 'wildcard'],
        'standard_min' => 0,
        'wildcard_min' => 0,
        'total_min' => 0,
    ], $productAttributes));
    $product->cost = [
        'price' => ['12' => '0'],
        'alternative_standard_price' => ['12' => '10.25'],
        'alternative_wildcard_price' => ['12' => '20.55'],
    ];
    $product->save();
    $payload = [
        'levels' => [[
            'code' => $level->code,
            'cost_rate' => '1.2000',
        ]],
        'precision' => 2,
        'force' => false,
        'sync_cost_rates' => false,
        'preview' => true,
    ];
    $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($admin)];
    $preview = $test->withHeaders($headers)
        ->postJson('/api/admin/product-price/initialize', $payload)
        ->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.can_execute', true);
    $test->withHeaders($headers)->postJson('/api/admin/product-price/initialize', [
        ...$payload,
        'preview' => false,
        'preview_token' => $preview->json('data.preview_token'),
    ])->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.executed', true);

    $price = ProductPrice::query()->where([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
    ])->firstOrFail();
    expect($price->getRawOriginal('price'))->toBe('0.00')
        ->and($price->getRawOriginal('alternative_standard_price'))->toBe('12.30')
        ->and($price->getRawOriginal('alternative_wildcard_price'))->toBe('24.66');

    return [$user, $product->refresh()];
}

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

test('初始化价格后 SSL 混合多域名按真实 SAN 数量计费且零 SAN 为零元', function () {
    [$user, $product] = initializeTaskFourOrderPrices($this);
    $order = $this->createTestOrder($user, $product, [
        'purchased_standard_count' => 0,
        'purchased_wildcard_count' => 0,
    ]);
    $cert = $this->createTestCert($order, [
        'standard_count' => 0,
        'wildcard_count' => 0,
    ]);

    expect(OrderUtil::getLatestCertAmount($order->fresh()->toArray(), $cert->toArray(), $product->toArray()))
        ->toBe('0.00');

    $cert->update(['standard_count' => 1]);
    expect(OrderUtil::getLatestCertAmount($order->fresh()->toArray(), $cert->fresh()->toArray(), $product->toArray()))
        ->toBe('12.30');

    $cert->update(['wildcard_count' => 1]);
    expect(OrderUtil::getLatestCertAmount($order->fresh()->toArray(), $cert->fresh()->toArray(), $product->toArray()))
        ->toBe('36.96');
});

test('初始化价格后 ACME 使用 purchased SAN 数量并按基础配额扣减', function () {
    [$user, $product] = initializeTaskFourOrderPrices($this, [
        'product_type' => Product::TYPE_ACME,
        'standard_min' => 1,
    ]);
    $acmeOrder = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'purchased_standard_count' => 2,
        'purchased_wildcard_count' => 1,
    ]);

    expect(OrderUtil::getLatestCertAmount($acmeOrder->toArray(), ['action' => 'new'], $product->toArray()))
        ->toBe('36.96');
});

test('初始化价格后重签只计算相对订单已购基线新增的 SAN', function () {
    [$user, $product] = initializeTaskFourOrderPrices($this, [
        'standard_min' => 2,
        'wildcard_min' => 1,
        'total_min' => 3,
    ]);
    $order = $this->createTestOrder($user, $product, [
        'purchased_standard_count' => 2,
        'purchased_wildcard_count' => 1,
    ]);
    $reissue = $this->createTestCert($order, [
        'action' => 'reissue',
        'standard_count' => 3,
        'wildcard_count' => 2,
    ]);

    expect(OrderUtil::getLatestCertAmount($order->fresh()->toArray(), $reissue->toArray(), $product->toArray()))
        ->toBe('36.96');
});
