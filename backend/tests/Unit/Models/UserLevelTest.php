<?php

use App\Models\ProductPrice;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// 关系参数顺序回归：users/product_prices 表无 code 列、user_levels 本地键才是 code，
// 错误的 hasMany(..., 'code', 'level_code') 会查 Column not found 或返回空

test('users 关系按 level_code 关联用户', function () {
    $level = UserLevel::factory()->create(['code' => 'rel_users']);
    User::factory()->create(['level_code' => 'rel_users']);
    User::factory()->create(['level_code' => 'standard']);

    expect($level->users()->count())->toBe(1);
});

test('customUsers 关系按 custom_level_code 关联用户', function () {
    $level = UserLevel::factory()->create(['code' => 'rel_custom']);
    User::factory()->create(['level_code' => 'standard', 'custom_level_code' => 'rel_custom']);
    User::factory()->create(['level_code' => 'standard']);

    expect($level->customUsers()->count())->toBe(1);
});

test('productPrices 关系按 level_code 关联产品价格', function () {
    $level = UserLevel::factory()->create(['code' => 'rel_price']);
    ProductPrice::factory()->create(['level_code' => 'rel_price']);
    ProductPrice::factory()->create(['level_code' => 'standard']);

    expect($level->productPrices()->count())->toBe(1);
});
