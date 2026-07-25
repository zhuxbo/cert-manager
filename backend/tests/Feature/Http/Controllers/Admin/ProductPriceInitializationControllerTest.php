<?php

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\UserLevel;
use App\Services\ProductPrice\ProductPriceInitializationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));
    config(['app.key' => 'task-four-controller-key']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function taskFourInitializationFixture(array $productAttributes = [], array $cost = []): array
{
    $admin = Admin::factory()->create();
    $level = UserLevel::factory()->create([
        'code' => 'task-four-level',
        'name' => 'Task Four Level',
        'cost_rate' => '1.0000',
    ]);
    $product = Product::factory()->create(array_replace([
        'periods' => [12],
        'alternative_name_types' => ['standard', 'wildcard'],
    ], $productAttributes));
    $product->cost = $cost ?: [
        'price' => ['12' => '0'],
        'alternative_standard_price' => ['12' => '10.25'],
        'alternative_wildcard_price' => ['12' => '20.55'],
    ];
    $product->save();

    return [$admin, $level, $product->refresh()];
}

function taskFourInitializationPayload(UserLevel $level, array $overrides = []): array
{
    return array_replace([
        'levels' => [[
            'code' => $level->code,
            'cost_rate' => '1.2000',
        ]],
        'precision' => 2,
        'force' => false,
        'sync_cost_rates' => false,
        'preview' => true,
    ], $overrides);
}

test('未认证管理员不能调用价格初始化接口', function () {
    $this->postJson('/api/admin/product-price/initialize', [])->assertUnauthorized();
});

test('预览成功返回令牌且即使开启强制和倍率同步也绝不写库', function () {
    [$admin, $level] = taskFourInitializationFixture();

    $response = $this->actingAsAdmin($admin)->postJson(
        '/api/admin/product-price/initialize',
        taskFourInitializationPayload($level, [
            'force' => true,
            'sync_cost_rates' => true,
            'preview_token' => '客户端伪造令牌应被忽略',
        ]),
    );

    $response->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.preview', true)
        ->assertJsonPath('data.can_execute', true)
        ->assertJsonPath('data.executed', false)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.warnings', []);

    expect($response->json('data.preview_token'))->toBeString()->not->toBeEmpty()
        ->and(ProductPrice::query()->count())->toBe(0)
        ->and($level->refresh()->getRawOriginal('cost_rate'))->toBe('1.0000');
});

test('成本告警仍以 code 一的结构化成功响应返回', function () {
    $admin = Admin::factory()->create();
    $level = UserLevel::factory()->create([
        'code' => 'task-four-warning',
        'name' => 'Task Four Warning',
        'cost_rate' => '1.0000',
    ]);
    $product = Product::factory()->create([
        'periods' => [12],
        'alternative_name_types' => ['standard'],
    ]);
    DB::table('products')->where('id', $product->id)->update([
        'cost' => json_encode(['price' => ['12' => '0']]),
    ]);

    $response = $this->actingAsAdmin($admin)->postJson(
        '/api/admin/product-price/initialize',
        taskFourInitializationPayload($level),
    );

    $response->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.can_execute', false)
        ->assertJsonPath('data.executed', false)
        ->assertJsonPath('data.reason', 'cost_validation')
        ->assertJsonPath('data.preview_token', null);
    expect($response->json('data.warnings'))->not->toBeEmpty()
        ->and($response->json('data.warnings.0'))->toHaveKeys([
            'product_id', 'product_name', 'period', 'field', 'message',
        ])
        ->and(ProductPrice::query()->count())->toBe(0);
});

test('代表性参数失败返回结构化校验错误', function () {
    [$admin, $level] = taskFourInitializationFixture();

    $this->actingAsAdmin($admin)->postJson(
        '/api/admin/product-price/initialize',
        taskFourInitializationPayload($level, ['precision' => 3]),
    )->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonValidationErrors('precision');

    expect(ProductPrice::query()->count())->toBe(0);
});

test('正式执行只信任服务端数据且响应统计与数据库一致', function () {
    [$admin, $level, $product] = taskFourInitializationFixture();
    $params = taskFourInitializationPayload($level, [
        'product_count' => 999,
        'target_count' => 999,
        'product_name' => '客户端伪造产品名',
        'price' => '0.01',
    ]);
    $serviceParams = $params;
    unset($serviceParams['preview']);
    $preview = app(ProductPriceInitializationService::class)->preview($serviceParams, $admin->id);

    $response = $this->actingAsAdmin($admin)->postJson(
        '/api/admin/product-price/initialize',
        [...$params, 'preview' => false, 'preview_token' => $preview['preview_token']],
    );

    $response->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.can_execute', true)
        ->assertJsonPath('data.executed', true)
        ->assertJsonPath('data.product_count', 1)
        ->assertJsonPath('data.level_count', 1)
        ->assertJsonPath('data.target_count', 1)
        ->assertJsonPath('data.created_count', 1)
        ->assertJsonPath('data.preserved_count', 0)
        ->assertJsonPath('data.warnings', []);

    $price = ProductPrice::query()->where([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
    ])->firstOrFail();
    expect(ProductPrice::query()->count())->toBe($response->json('data.target_count'))
        ->and($price->getRawOriginal('price'))->toBe('0.00')
        ->and($price->getRawOriginal('alternative_standard_price'))->toBe('12.30')
        ->and($price->getRawOriginal('alternative_wildcard_price'))->toBe('24.66');
});

test('状态变化后执行返回结构化 stale 且零写', function () {
    [$admin, $level, $product] = taskFourInitializationFixture();
    $params = taskFourInitializationPayload($level);
    $serviceParams = $params;
    unset($serviceParams['preview']);
    $preview = app(ProductPriceInitializationService::class)->preview($serviceParams, $admin->id);
    $product->cost = [
        'price' => ['12' => '0'],
        'alternative_standard_price' => ['12' => '11.25'],
        'alternative_wildcard_price' => ['12' => '20.55'],
    ];
    $product->save();

    $this->actingAsAdmin($admin)->postJson(
        '/api/admin/product-price/initialize',
        [...$params, 'preview' => false, 'preview_token' => $preview['preview_token']],
    )->assertOk()
        ->assertJsonPath('code', 1)
        ->assertJsonPath('data.can_execute', false)
        ->assertJsonPath('data.executed', false)
        ->assertJsonPath('data.reason', 'stale_preview')
        ->assertJsonPath('data.warnings', []);

    expect(ProductPrice::query()->count())->toBe(0);
});
