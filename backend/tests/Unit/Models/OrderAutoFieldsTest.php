<?php

use App\Models\Order;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
});

/**
 * 测试 auto_renew 正确转换为 boolean
 */
test('auto renew casts to boolean', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'brand' => 'test',
        'period' => 12,
        'auto_renew' => true,
    ]);

    expect($order->auto_renew)->toBeTrue();

    $order->auto_renew = false;
    $order->save();
    $order->refresh();

    expect($order->auto_renew)->toBeFalse();
});

/**
 * 测试 auto_reissue 正确转换为 boolean
 */
test('auto reissue casts to boolean', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'brand' => 'test',
        'period' => 12,
        'auto_reissue' => true,
    ]);

    expect($order->auto_reissue)->toBeTrue();

    $order->auto_reissue = false;
    $order->save();
    $order->refresh();

    expect($order->auto_reissue)->toBeFalse();
});

/**
 * 测试允许 null 值以便回落到用户设置
 */
test('allows null for fallback', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'brand' => 'test',
        'period' => 12,
        'auto_renew' => null,
        'auto_reissue' => null,
    ]);

    expect($order->auto_renew)->toBeNull();
    expect($order->auto_reissue)->toBeNull();
});

/**
 * 测试整数转换为 boolean
 */
test('casts integer to boolean', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'brand' => 'test',
        'period' => 12,
        'auto_renew' => 1,
        'auto_reissue' => 0,
    ]);

    expect($order->auto_renew)->toBeTrue();
    expect($order->auto_reissue)->toBeFalse();
});

/**
 * 测试默认创建时字段为 null
 */
test('defaults to null on create', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'brand' => 'test',
        'period' => 12,
    ]);

    expect($order->auto_renew)->toBeNull();
    expect($order->auto_reissue)->toBeNull();
});
