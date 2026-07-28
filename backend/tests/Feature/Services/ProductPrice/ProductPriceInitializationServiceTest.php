<?php

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\UserLevel;
use App\Services\ProductPrice\ProductPriceInitializationService;
use App\Services\ProductPrice\ProductPriceMutationLock;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));
    config(['app.key' => 'task-three-initialization-key']);
    $this->admin = Admin::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function taskThreeInitializationParams(array $levels, array $overrides = []): array
{
    return array_replace([
        'levels' => $levels,
        'precision' => 2,
        'force' => false,
        'sync_cost_rates' => false,
    ], $overrides);
}

function taskThreeLevel(string $code = 'task-three-standard', string $rate = '1.0000', int $custom = 0): UserLevel
{
    return UserLevel::factory()->create([
        'code' => $code,
        'name' => "Task Three $code",
        'cost_rate' => $rate,
        'custom' => $custom,
    ]);
}

function taskThreeVerifiedProduct(array $attributes = [], array $cost = []): Product
{
    $product = Product::factory()->create(array_replace([
        'periods' => [12],
        'alternative_name_types' => [],
    ], $attributes));
    $product->cost = $cost ?: [
        'price' => ['12' => '100'],
    ];
    $product->save();

    return $product->refresh();
}

function taskThreePreviewAndInitialize(array $params, int $adminId): array
{
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $adminId);

    expect($preview['can_execute'])->toBeTrue()
        ->and($preview['preview_token'])->toBeString()
        ->and($preview)->not->toHaveKeys(['rows', 'prices', 'targets']);

    return $service->initialize($params, $adminId, $preview['preview_token']);
}

test('混合多域名产品只按适用同名成本倍乘', function () {
    $level = taskThreeLevel();
    $product = taskThreeVerifiedProduct([
        'status' => 1,
        'alternative_name_types' => ['standard'],
    ], [
        'price' => ['12' => '0'],
        'alternative_standard_price' => ['12' => '10.25'],
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.2000'],
    ]);

    $result = taskThreePreviewAndInitialize($params, $this->admin->id);
    $row = ProductPrice::query()->where('product_id', $product->id)->firstOrFail();

    expect($result)->toMatchArray([
        'preview' => false,
        'can_execute' => true,
        'executed' => true,
        'product_count' => 1,
        'level_count' => 1,
        'target_count' => 1,
        'created_count' => 1,
        'preserved_count' => 0,
    ])->and($row->getRawOriginal('price'))->toBe('0.00')
        ->and($row->getRawOriginal('alternative_standard_price'))->toBe('12.30')
        ->and($row->getRawOriginal('alternative_wildcard_price'))->toBe('0.00')
        ->and($level->refresh()->getRawOriginal('cost_rate'))->toBe('1.0000');
});

test('预览和强制执行完整排除禁用产品并保留其历史价格', function () {
    $level = taskThreeLevel();
    $enabled = taskThreeVerifiedProduct(['status' => 1]);
    $disabled = Product::factory()->create([
        'periods' => [12],
        'alternative_name_types' => [],
        'status' => 0,
    ]);
    DB::table('products')->where('id', $disabled->id)->update([
        'cost' => json_encode(['price' => []]),
    ]);
    $disabledPrice = ProductPrice::factory()->create([
        'product_id' => $disabled->id,
        'level_code' => $level->code,
        'period' => 12,
        'price' => '7.77',
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ], ['force' => true]);
    $service = app(ProductPriceInitializationService::class);

    $preview = $service->preview($params, $this->admin->id);

    expect($preview)->toMatchArray([
        'can_execute' => true,
        'product_count' => 1,
        'target_count' => 1,
        'warnings' => [],
    ]);

    $disabledPrice->update(['price' => '8.88']);
    $result = $service->initialize($params, $this->admin->id, $preview['preview_token']);

    expect($result)->toMatchArray([
        'can_execute' => true,
        'executed' => true,
        'product_count' => 1,
        'target_count' => 1,
        'deleted_count' => 0,
        'rebuilt_count' => 1,
    ])->and(ProductPrice::query()->where('product_id', $enabled->id)->count())->toBe(1)
        ->and($disabledPrice->refresh()->getRawOriginal('price'))->toBe('8.88');
});

test('重复周期只生成一个目标且初始化只写入一行', function () {
    $level = taskThreeLevel();
    $product = taskThreeVerifiedProduct(['periods' => [12, 12]]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ]);
    $service = app(ProductPriceInitializationService::class);

    $preview = $service->preview($params, $this->admin->id);

    expect($preview['can_execute'])->toBeTrue()
        ->and($preview['preview_token'])->toBeString()
        ->and(ProductPrice::query()->count())->toBe(0);

    $result = $service->initialize($params, $this->admin->id, $preview['preview_token']);

    expect($preview['target_count'])->toBe(1)
        ->and($result)->toMatchArray([
            'can_execute' => true,
            'executed' => true,
            'target_count' => 1,
            'created_count' => 1,
            'preserved_count' => 0,
        ])->and(ProductPrice::query()->where([
            'product_id' => $product->id,
            'level_code' => $level->code,
            'period' => 12,
        ])->count())->toBe(1);
});

test('默认补齐只插缺失唯一键并整行保留既有价格', function () {
    $level = taskThreeLevel();
    $first = taskThreeVerifiedProduct(['periods' => [12, 24]], [
        'price' => ['12' => '100', '24' => '200'],
    ]);
    ProductPrice::factory()->create([
        'product_id' => $first->id,
        'level_code' => $level->code,
        'period' => 12,
        'price' => '9.99',
        'alternative_standard_price' => '8.88',
        'alternative_wildcard_price' => '7.77',
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '2.0000'],
    ]);

    $result = taskThreePreviewAndInitialize($params, $this->admin->id);
    $preserved = ProductPrice::query()->where([
        'product_id' => $first->id,
        'level_code' => $level->code,
        'period' => 12,
    ])->firstOrFail();

    expect($result['created_count'] + $result['preserved_count'])->toBe($result['target_count'])
        ->and($result['created_count'])->toBe(1)
        ->and($result['preserved_count'])->toBe(1)
        ->and($preserved->getRawOriginal('price'))->toBe('9.99')
        ->and($preserved->getRawOriginal('alternative_standard_price'))->toBe('8.88')
        ->and($preserved->getRawOriginal('alternative_wildcard_price'))->toBe('7.77');
});

test('强制重建只删除选中级别并保留未选级别', function () {
    $selected = taskThreeLevel('task-three-selected');
    $unselected = taskThreeLevel('task-three-unselected', custom: 1);
    $product = taskThreeVerifiedProduct();
    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $selected->code,
        'period' => 12,
        'price' => '9.99',
    ]);
    $untouched = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $unselected->code,
        'period' => 12,
        'price' => '8.88',
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $selected->code, 'cost_rate' => '1.5000'],
    ], ['force' => true]);

    $result = taskThreePreviewAndInitialize($params, $this->admin->id);

    expect($result['deleted_count'])->toBe(1)
        ->and($result['rebuilt_count'])->toBe($result['target_count'])
        ->and(ProductPrice::query()->findOrFail($untouched->id)->getRawOriginal('price'))->toBe('8.88')
        ->and(ProductPrice::query()->where('level_code', $selected->code)->firstOrFail()->getRawOriginal('price'))
        ->toBe('150.00');
});

test('预览零写且成本告警不签 token', function () {
    $level = taskThreeLevel();
    taskThreeVerifiedProduct();
    $invalid = Product::factory()->create([
        'periods' => [12],
        'alternative_name_types' => [],
        'status' => 1,
    ]);
    DB::table('products')->where('id', $invalid->id)->update([
        'cost' => json_encode(['price' => []]),
    ]);
    $originalRate = $level->getRawOriginal('cost_rate');
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '2.0000'],
    ], ['force' => true, 'sync_cost_rates' => true]);

    $result = app(ProductPriceInitializationService::class)->preview($params, $this->admin->id);

    expect($result)->toMatchArray([
        'preview' => true,
        'can_execute' => false,
        'executed' => false,
        'reason' => 'cost_validation',
        'preview_token' => null,
        'product_count' => 2,
        'target_count' => 2,
    ])->and($result['warnings'])->not->toBeEmpty()
        ->and(collect($result['warnings'])->pluck('field')->all())->toContain('price')
        ->and($result['warnings'][0])->toHaveKeys(['product_id', 'product_name', 'period', 'field', 'message'])
        ->and(ProductPrice::query()->count())->toBe(0)
        ->and($level->refresh()->getRawOriginal('cost_rate'))->toBe($originalRate);
});

test('纯主价零成本和适用 SAN 零成本均阻断', function (array $types, array $cost, string $field) {
    $level = taskThreeLevel();
    taskThreeVerifiedProduct(['alternative_name_types' => $types], $cost);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ]);

    $result = app(ProductPriceInitializationService::class)->preview($params, $this->admin->id);

    expect($result['can_execute'])->toBeFalse()
        ->and(collect($result['warnings'])->pluck('field')->all())->toContain($field);
})->with([
    '纯主价零成本' => [[], ['price' => ['12' => '0']], 'price'],
    '适用标准 SAN 零成本' => [
        ['standard'],
        ['price' => ['12' => '1'], 'alternative_standard_price' => ['12' => '0']],
        'alternative_standard_price',
    ],
]);

test('正成本按 half up 临界值换算且低于临界值阻断', function (string $cost, int $precision, bool $valid, string $expected) {
    $level = taskThreeLevel();
    $product = taskThreeVerifiedProduct([], ['price' => ['12' => $cost]]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ], ['precision' => $precision]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);

    expect($preview['can_execute'])->toBe($valid);

    if (! $valid) {
        expect(collect($preview['warnings'])->pluck('field')->all())->toContain('price');

        return;
    }

    $service->initialize($params, $this->admin->id, $preview['preview_token']);
    expect(ProductPrice::query()->where('product_id', $product->id)->firstOrFail()->getRawOriginal('price'))
        ->toBe($expected);
})->with([
    '分以下' => ['0.0049', 2, false, ''],
    '超高小数微值' => ['0.0000000000001', 2, false, ''],
    '分临界' => ['0.0050', 2, true, '0.01'],
    '角以下' => ['0.049', 1, false, ''],
    '角临界' => ['0.050', 1, true, '0.10'],
    '元以下' => ['0.49', 0, false, ''],
    '元临界' => ['0.50', 0, true, '1.00'],
]);

test('售价超过单字段上限时阻断', function () {
    $level = taskThreeLevel();
    taskThreeVerifiedProduct([], ['price' => ['12' => '999998.01']]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ]);

    $result = app(ProductPriceInitializationService::class)->preview($params, $this->admin->id);

    expect($result['can_execute'])->toBeFalse()
        ->and(collect($result['warnings'])->pluck('field')->all())->toContain('price');
});

test('预览后任一指纹状态变化都 stale 且强制模式在删除前零写', function (string $mutation) {
    $level = taskThreeLevel();
    $product = taskThreeVerifiedProduct();
    $existing = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
        'price' => '9.99',
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.5000'],
    ], ['force' => true, 'sync_cost_rates' => true]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);

    match ($mutation) {
        'cost' => tap($product, function (Product $product) {
            $product->cost = ['price' => ['12' => '101']];
            $product->save();
        }),
        'periods' => $product->update(['periods' => [12, 24]]),
        'status' => $product->update(['status' => 0]),
        'level' => $level->update(['cost_rate' => '1.1000']),
        'price' => $existing->update(['price' => '8.88']),
        'new-price' => ProductPrice::factory()->create([
            'product_id' => taskThreeVerifiedProduct()->id,
            'level_code' => $level->code,
            'period' => 12,
        ]),
    };
    $priceCount = ProductPrice::query()->count();
    $currentExistingPrice = $existing->refresh()->getRawOriginal('price');
    $currentRate = $level->refresh()->getRawOriginal('cost_rate');

    $result = $service->initialize($params, $this->admin->id, $preview['preview_token']);

    expect($result)->toMatchArray([
        'can_execute' => false,
        'executed' => false,
        'reason' => 'stale_preview',
    ])->and(ProductPrice::query()->count())->toBe($priceCount)
        ->and($existing->refresh()->getRawOriginal('price'))->toBe($currentExistingPrice)
        ->and($level->refresh()->getRawOriginal('cost_rate'))->toBe($currentRate);
})->with(['cost', 'periods', 'status', 'level', 'price', 'new-price']);

test('同秒强制删除重插同值后旧 token 不可重放且价格行 ID 不再变化', function () {
    $level = taskThreeLevel();
    $product = taskThreeVerifiedProduct();
    $existing = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
        'price' => '100.00',
        'alternative_standard_price' => '0.00',
        'alternative_wildcard_price' => '0.00',
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ], ['force' => true]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);
    $sameStatePreview = $service->preview($params, $this->admin->id);

    expect($sameStatePreview['preview_token'])->toBe($preview['preview_token']);

    $first = $service->initialize($params, $this->admin->id, $preview['preview_token']);
    $rebuilt = ProductPrice::query()->where([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
    ])->firstOrFail();

    expect($first['executed'])->toBeTrue()
        ->and($rebuilt->id)->not->toBe($existing->id);

    $second = $service->initialize($params, $this->admin->id, $preview['preview_token']);

    expect($second)->toMatchArray([
        'can_execute' => false,
        'executed' => false,
        'reason' => 'stale_preview',
    ])->and(ProductPrice::query()->where([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
    ])->value('id'))->toBe($rebuilt->id);
});

test('无效过期管理员或参数不一致 token 统一 stale 且零写', function (string $case) {
    $level = taskThreeLevel();
    taskThreeVerifiedProduct();
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.5000'],
    ], ['sync_cost_rates' => true]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);
    $token = $preview['preview_token'];
    $adminId = $this->admin->id;
    $executeParams = $params;

    match ($case) {
        'invalid' => $token = 'invalid-token',
        'expired' => Carbon::setTestNow(now()->addMinutes(11)),
        'admin' => $adminId = Admin::factory()->create()->id,
        'params' => $executeParams['precision'] = 1,
    };

    $result = $service->initialize($executeParams, $adminId, $token);

    expect($result)->toMatchArray([
        'can_execute' => false,
        'executed' => false,
        'reason' => 'stale_preview',
    ])->and(ProductPrice::query()->count())->toBe(0)
        ->and($level->refresh()->getRawOriginal('cost_rate'))->toBe('1.0000');
})->with(['invalid', 'expired', 'admin', 'params']);

test('同步倍率与默认无新增写入同事务且既有价格不变', function () {
    $level = taskThreeLevel();
    $product = taskThreeVerifiedProduct();
    $price = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
        'price' => '9.99',
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.5000'],
    ], ['sync_cost_rates' => true]);

    $result = taskThreePreviewAndInitialize($params, $this->admin->id);

    expect($result['created_count'])->toBe(0)
        ->and($result['preserved_count'])->toBe(1)
        ->and($result['synced_level_count'])->toBe(1)
        ->and($level->refresh()->getRawOriginal('cost_rate'))->toBe('1.5000')
        ->and($price->refresh()->getRawOriginal('price'))->toBe('9.99');
});

test('执行复用同一个产品价格命名锁', function () {
    $level = taskThreeLevel();
    taskThreeVerifiedProduct();
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);
    $realLock = app(ProductPriceMutationLock::class);
    $lock = Mockery::mock(ProductPriceMutationLock::class);
    $lock->shouldReceive('runWithLock')->once()->andReturnUsing(
        fn (Closure $callback) => $realLock->runWithLock($callback)
    );
    app()->instance(ProductPriceMutationLock::class, $lock);
    $service = app(ProductPriceInitializationService::class);

    $result = $service->initialize($params, $this->admin->id, $preview['preview_token']);

    expect($result['executed'])->toBeTrue();
});

test('普通插入异常会回滚倍率同步和所有价格', function () {
    $level = taskThreeLevel();
    taskThreeVerifiedProduct();
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.5000'],
    ], ['sync_cost_rates' => true]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);
    $failInsert = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$failInsert) {
        if ($failInsert && str_starts_with(strtolower(ltrim($query)), 'insert into `product_prices`')) {
            $failInsert = false;
            throw new RuntimeException('task three forced insert failure');
        }
    });

    expect(fn () => $service->initialize($params, $this->admin->id, $preview['preview_token']))
        ->toThrow(RuntimeException::class, 'task three forced insert failure')
        ->and(ProductPrice::query()->count())->toBe(0)
        ->and($level->refresh()->getRawOriginal('cost_rate'))->toBe('1.0000');
});

test('目标唯一键少插一行时拒绝恒等式伪通过并整体回滚', function () {
    $level = taskThreeLevel();
    $product = taskThreeVerifiedProduct(['periods' => [12, 24]], [
        'price' => ['12' => '100', '24' => '200'],
    ]);
    $params = taskThreeInitializationParams([
        ['code' => $level->code, 'cost_rate' => '1.0000'],
    ]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);
    $removeOneTarget = true;
    DB::listen(function (QueryExecuted $query) use (&$removeOneTarget, $product, $level) {
        if ($removeOneTarget && str_starts_with(strtolower(ltrim($query->sql)), 'insert into `product_prices`')) {
            $removeOneTarget = false;
            DB::table('product_prices')->where([
                'product_id' => $product->id,
                'level_code' => $level->code,
                'period' => 24,
            ])->delete();
        }
    });

    expect(fn () => $service->initialize($params, $this->admin->id, $preview['preview_token']))
        ->toThrow(LogicException::class, '产品价格目标唯一键覆盖不完整')
        ->and(ProductPrice::query()->count())->toBe(0);
});

test('超过五百目标时第二批 insert 失败会回滚首批真实写入', function () {
    $periods = range(1, 30);
    $costs = array_fill_keys(array_map('strval', $periods), '1');
    taskThreeVerifiedProduct(['periods' => $periods], ['price' => $costs]);
    $levels = [];
    for ($index = 1; $index <= 17; $index++) {
        $level = taskThreeLevel("task-three-batch-$index");
        $levels[] = ['code' => $level->code, 'cost_rate' => '1.0000'];
    }
    $params = taskThreeInitializationParams($levels);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);
    $insertCount = 0;
    DB::connection()->beforeExecuting(function (string $query) use (&$insertCount) {
        if (str_starts_with(strtolower(ltrim($query)), 'insert into `product_prices`')) {
            $insertCount++;
            if ($insertCount === 2) {
                throw new RuntimeException('task three second chunk failure');
            }
        }
    });

    expect($preview['target_count'])->toBe(510)
        ->and(fn () => $service->initialize($params, $this->admin->id, $preview['preview_token']))
        ->toThrow(RuntimeException::class, 'task three second chunk failure')
        ->and(ProductPrice::query()->count())->toBe(0)
        ->and($insertCount)->toBe(2);
});

test('第二个倍率更新失败会回滚首倍率和已插价格', function () {
    $first = taskThreeLevel('task-three-rate-a');
    $second = taskThreeLevel('task-three-rate-b');
    taskThreeVerifiedProduct();
    $params = taskThreeInitializationParams([
        ['code' => $first->code, 'cost_rate' => '1.5000'],
        ['code' => $second->code, 'cost_rate' => '1.6000'],
    ], ['sync_cost_rates' => true]);
    $service = app(ProductPriceInitializationService::class);
    $preview = $service->preview($params, $this->admin->id);
    $updateCount = 0;
    DB::connection()->beforeExecuting(function (string $query) use (&$updateCount) {
        if (str_starts_with(strtolower(ltrim($query)), 'update `user_levels` set')) {
            $updateCount++;
            if ($updateCount === 2) {
                throw new RuntimeException('task three second rate failure');
            }
        }
    });

    expect(fn () => $service->initialize($params, $this->admin->id, $preview['preview_token']))
        ->toThrow(RuntimeException::class, 'task three second rate failure')
        ->and(ProductPrice::query()->count())->toBe(0)
        ->and($first->refresh()->getRawOriginal('cost_rate'))->toBe('1.0000')
        ->and($second->refresh()->getRawOriginal('cost_rate'))->toBe('1.0000')
        ->and($updateCount)->toBe(2);
});
