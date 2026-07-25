<?php

use App\Exceptions\ProductPriceMutationBusyException;
use App\Exceptions\ProductPriceMutationLockException;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\UserLevel;
use App\Services\ProductPrice\ProductPriceMutationLock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->productPriceLockHolder = null;
});

afterEach(function () {
    if ($this->productPriceLockHolder instanceof Connection) {
        try {
            $this->productPriceLockHolder->selectOne(
                'SELECT RELEASE_LOCK(?) AS released',
                [productPriceMutationLockKey()]
            );
        } catch (Throwable) {
            // 故障路径可能已令连接不可用，测试清理只做 best effort。
        }

        DB::purge('product_price_lock_holder');
    }
});

function productPriceMutationLockKey(): string
{
    $token = getenv('TEST_TOKEN');

    return 'ssl-manager:product-price:mutation:'.($token === false || $token === '' ? 'single' : $token);
}

function holdProductPriceMutationLock(object $test): void
{
    $default = config('database.default');
    config(['database.connections.product_price_lock_holder' => config("database.connections.$default")]);
    DB::purge('product_price_lock_holder');

    $test->productPriceLockHolder = DB::connection('product_price_lock_holder');
    $result = $test->productPriceLockHolder->selectOne(
        'SELECT GET_LOCK(?, 0) AS acquired',
        [productPriceMutationLockKey()]
    );

    expect((int) ($result->acquired ?? 0))->toBe(1);
}

function mockedProductPriceMutationLock(mixed $acquired, mixed $released = 1): array
{
    $database = Mockery::mock(DatabaseManager::class);
    $connection = Mockery::mock(Connection::class);

    $database->shouldReceive('connection')->once()->andReturn($connection);
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT GET_LOCK(?, 0) AS acquired', [productPriceMutationLockKey()])
        ->andReturn($acquired === null ? null : (object) ['acquired' => $acquired]);

    if ($acquired === 1 || $acquired === '1') {
        $connection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT RELEASE_LOCK(?) AS released', [productPriceMutationLockKey()])
            ->andReturn($released === null ? null : (object) ['released' => $released]);
    }

    return [new ProductPriceMutationLock($database), $database, $connection];
}

test('异常路径 finally 释放命名锁', function () {
    $lock = app(ProductPriceMutationLock::class);

    expect(fn () => $lock->runWithLock(function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    assertProductPriceMutationLockAvailableFromIndependentConnection();
});

function assertProductPriceMutationLockAvailableFromIndependentConnection(): void
{
    $default = config('database.default');
    config(['database.connections.product_price_lock_probe' => config("database.connections.$default")]);
    DB::purge('product_price_lock_probe');
    $probe = DB::connection('product_price_lock_probe');

    try {
        $acquired = $probe->selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            [productPriceMutationLockKey()]
        );
        expect((int) ($acquired->acquired ?? 0))->toBe(1);

        $released = $probe->selectOne(
            'SELECT RELEASE_LOCK(?) AS released',
            [productPriceMutationLockKey()]
        );
        expect((int) ($released->released ?? 0))->toBe(1);
    } finally {
        DB::purge('product_price_lock_probe');
    }
}

test('第二连接占锁时快速抛出忙异常', function () {
    holdProductPriceMutationLock($this);

    expect(fn () => app(ProductPriceMutationLock::class)->runWithLock(fn () => 'unreachable'))
        ->toThrow(ProductPriceMutationBusyException::class, '产品价格正在变更，请稍后重试');
});

test('获取命名锁返回 NULL 时按基础设施异常 fail closed', function () {
    [$lock] = mockedProductPriceMutationLock(null);

    expect(fn () => $lock->runWithLock(fn () => 'unreachable'))
        ->toThrow(ProductPriceMutationLockException::class);
});

test('获取命名锁只接受严格的 0 和 1 返回值', function ($acquired) {
    $database = Mockery::mock(DatabaseManager::class);
    $connection = Mockery::mock(Connection::class);
    $database->shouldReceive('connection')->once()->andReturn($connection);
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT GET_LOCK(?, 0) AS acquired', [productPriceMutationLockKey()])
        ->andReturn((object) ['acquired' => $acquired]);
    $connection->shouldReceive('selectOne')
        ->zeroOrMoreTimes()
        ->with('SELECT RELEASE_LOCK(?) AS released', [productPriceMutationLockKey()])
        ->andReturn((object) ['released' => 1]);

    $callbackCalled = false;
    $lock = new ProductPriceMutationLock($database);

    expect(fn () => $lock->runWithLock(function () use (&$callbackCalled) {
        $callbackCalled = true;
    }))->toThrow(ProductPriceMutationLockException::class)
        ->and($callbackCalled)->toBeFalse();
})->with([
    '布尔 false' => false,
    '布尔 true' => true,
    '前导零字符串' => '01',
    '小数字符串' => '1.0',
    '整数 2' => 2,
]);

test('获取命名锁接受字符串 1 并正常执行和释放', function () {
    [$lock] = mockedProductPriceMutationLock('1');

    expect($lock->runWithLock(fn () => 'done'))->toBe('done');
});

test('获取命名锁接受字符串 0 并抛忙异常', function () {
    [$lock] = mockedProductPriceMutationLock('0');

    expect(fn () => $lock->runWithLock(fn () => 'unreachable'))
        ->toThrow(ProductPriceMutationBusyException::class);
});

test('获取命名锁 SQL 异常时按基础设施异常 fail closed', function () {
    $database = Mockery::mock(DatabaseManager::class);
    $connection = Mockery::mock(Connection::class);
    $database->shouldReceive('connection')->once()->andReturn($connection);
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT GET_LOCK(?, 0) AS acquired', [productPriceMutationLockKey()])
        ->andThrow(new RuntimeException('get lock failed'));

    $lock = new ProductPriceMutationLock($database);

    expect(fn () => $lock->runWithLock(fn () => 'unreachable'))
        ->toThrow(ProductPriceMutationLockException::class);
});

test('释放命名锁返回非 1 时断开原连接且不返回 callback 成功结果', function ($released) {
    [$lock, $database, $connection] = mockedProductPriceMutationLock(1, $released);
    $connection->shouldReceive('getName')->once()->andReturn('mysql');
    $database->shouldReceive('disconnect')->once()->with('mysql');
    Log::shouldReceive('critical')->once()->with(
        '[product_price] 命名锁释放失败',
        Mockery::on(fn (array $context) => $context['exception'] instanceof Throwable)
    );

    expect(fn () => $lock->runWithLock(fn () => 'must-not-return'))
        ->toThrow(ProductPriceMutationLockException::class);
})->with([
    '释放返回 0' => 0,
    '释放返回 NULL' => null,
    '释放返回布尔 false' => false,
    '释放返回布尔 true' => true,
    '释放返回前导零字符串' => '01',
    '释放返回小数字符串' => '1.0',
    '释放返回整数 2' => 2,
]);

test('释放命名锁 SQL 异常时断开原连接且不返回 callback 成功结果', function () {
    $database = Mockery::mock(DatabaseManager::class);
    $connection = Mockery::mock(Connection::class);
    $database->shouldReceive('connection')->once()->andReturn($connection);
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT GET_LOCK(?, 0) AS acquired', [productPriceMutationLockKey()])
        ->andReturn((object) ['acquired' => 1]);
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT RELEASE_LOCK(?) AS released', [productPriceMutationLockKey()])
        ->andThrow(new RuntimeException('release failed'));
    $connection->shouldReceive('getName')->once()->andReturn('mysql');
    $database->shouldReceive('disconnect')->once()->with('mysql');
    Log::shouldReceive('critical')->once();

    $lock = new ProductPriceMutationLock($database);

    expect(fn () => $lock->runWithLock(fn () => 'must-not-return'))
        ->toThrow(ProductPriceMutationLockException::class);
});

test('callback 和释放同时失败时保留 callback 异常并断开原连接', function () {
    [$lock, $database, $connection] = mockedProductPriceMutationLock(1, 0);
    $connection->shouldReceive('getName')->once()->andReturn('mysql');
    $database->shouldReceive('disconnect')->once()->with('mysql');
    Log::shouldReceive('critical')->once();
    $callbackError = new RuntimeException('callback failed');

    try {
        $lock->runWithLock(fn () => throw $callbackError);
        test()->fail('callback 异常必须继续对外抛出');
    } catch (Throwable $caught) {
        expect($caught)->toBe($callbackError);
    }
});

test('五个产品价格写入口共用同一命名锁并返回 503 专用文案', function () {
    $level = UserLevel::factory()->create(['code' => 'lock-level', 'name' => '锁测试级别']);
    $product = Product::factory()->create();
    $price = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
    ]);
    holdProductPriceMutationLock($this);

    $requests = [
        fn () => $this->actingAsAdmin($this->admin)->postJson('/api/admin/product-price', [
            'product_id' => $product->id,
            'level_code' => $level->code,
            'period' => 24,
            'price' => '100.00',
            'alternative_standard_price' => '0.00',
            'alternative_wildcard_price' => '0.00',
        ]),
        fn () => $this->actingAsAdmin($this->admin)->putJson("/api/admin/product-price/{$price->id}", [
            'product_id' => $product->id,
            'level_code' => $level->code,
            'period' => 12,
            'price' => '200.00',
            'alternative_standard_price' => '0.00',
            'alternative_wildcard_price' => '0.00',
        ]),
        fn () => $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/product-price/{$price->id}"),
        fn () => $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/product-price/batch', [
            'ids' => [$price->id],
        ]),
        fn () => $this->actingAsAdmin($this->admin)->patchJson('/api/admin/product-price/prices', [
            'product_id' => $product->id,
            'product_price' => [
                $level->code => ['price' => [12 => '300.00']],
            ],
        ]),
    ];

    foreach ($requests as $request) {
        $request()
            ->assertStatus(503)
            ->assertJson([
                'code' => 0,
                'msg' => '产品价格正在变更，请稍后重试',
            ]);
    }
});

test('价格插入异常后下一次请求可重新取得命名锁', function () {
    $level = UserLevel::factory()->create(['code' => 'insert-lock', 'name' => '插入锁级别']);
    $product = Product::factory()->create();
    $failNextInsert = true;
    $event = 'eloquent.creating: '.ProductPrice::class;

    Event::listen($event, function () use (&$failNextInsert) {
        if ($failNextInsert) {
            $failNextInsert = false;
            throw new RuntimeException('insert failed');
        }
    });

    $payload = [
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
        'price' => '100.00',
        'alternative_standard_price' => '0.00',
        'alternative_wildcard_price' => '0.00',
    ];

    try {
        $this->actingAsAdmin($this->admin)->postJson('/api/admin/product-price', $payload)
            ->assertStatus(400)
            ->assertJson(['code' => 0]);

        assertProductPriceMutationLockAvailableFromIndependentConnection();

        $this->actingAsAdmin($this->admin)->postJson('/api/admin/product-price', $payload)
            ->assertOk()
            ->assertJson(['code' => 1]);
    } finally {
        Event::forget($event);
    }
});

test('store 组合冲突返回稳定业务错误且不暴露唯一键异常', function () {
    $level = UserLevel::factory()->create(['code' => 'store-duplicate', 'name' => '新增冲突级别']);
    $price = ProductPrice::factory()->create(['level_code' => $level->code]);

    $this->actingAsAdmin($this->admin)->postJson('/api/admin/product-price', [
        'product_id' => $price->product_id,
        'level_code' => $price->level_code,
        'period' => $price->period,
        'price' => '100.00',
        'alternative_standard_price' => '0.00',
        'alternative_wildcard_price' => '0.00',
    ])->assertOk()->assertJson([
        'code' => 0,
        'msg' => '该产品、用户级别和周期的组合已经存在。',
    ]);
});

test('update 组合冲突返回稳定业务错误且保留原价格', function () {
    $level = UserLevel::factory()->create(['code' => 'update-duplicate', 'name' => '更新冲突级别']);
    $product = Product::factory()->create();
    $existing = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 12,
    ]);
    $updating = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'level_code' => $level->code,
        'period' => 24,
        'price' => '456.00',
    ]);

    $this->actingAsAdmin($this->admin)->putJson("/api/admin/product-price/{$updating->id}", [
        'product_id' => $existing->product_id,
        'level_code' => $existing->level_code,
        'period' => $existing->period,
        'price' => '100.00',
        'alternative_standard_price' => '0.00',
        'alternative_wildcard_price' => '0.00',
    ])->assertOk()->assertJson([
        'code' => 0,
        'msg' => '该产品、用户级别和周期的组合已经存在。',
    ]);

    expect($updating->fresh())
        ->period->toBe(24)
        ->price->toBe('456.00');
});
