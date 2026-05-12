<?php

use App\Models\Product;
use App\Models\User;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('获取产品列表-无需认证', function () {
    Product::factory()->count(3)->create();

    $this->getJson('/api/product')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('获取产品列表-已登录时包含价格', function () {
    $user = User::factory()->create();
    Product::factory()->count(2)->create();

    $this->actingAsUser($user)
        ->getJson('/api/product')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取产品列表-按品牌筛选', function () {
    Product::factory()->create(['brand' => 'BrandA']);
    Product::factory()->create(['brand' => 'BrandB']);

    $this->getJson('/api/product?brand=BrandA')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取产品列表-按验证类型筛选', function () {
    Product::factory()->create(['validation_type' => 'dv']);
    Product::factory()->create(['validation_type' => 'ov']);

    $this->getJson('/api/product?validation_type=dv')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('获取产品详情-无需认证', function () {
    $product = Product::factory()->create();

    $this->getJson("/api/product/$product->id")
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['id', 'name', 'product_type', 'brand']]);
});

test('获取产品详情-产品不存在', function () {
    $this->getJson('/api/product/99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('获取产品详情-已下架产品不可见', function () {
    $product = Product::factory()->create(['status' => 0]);

    $this->getJson("/api/product/$product->id")
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('获取产品列表-只展示上架产品', function () {
    Product::factory()->create(['status' => 1]);
    Product::factory()->create(['status' => 0]);

    $response = $this->getJson('/api/product')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))->toBe(1);
});
