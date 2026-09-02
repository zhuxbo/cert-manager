<?php

use App\Models\Admin;
use App\Models\Chain;
use App\Models\User;
use App\Models\UserLevel;

test('Chain 工厂批量创建不会命中 common_name 唯一索引', function () {
    // 该断言用于守卫历史 flaky：factory 生成值必须与唯一索引对齐
    Chain::factory()->count(30)->create();

    expect(Chain::query()->distinct()->count('common_name'))->toBe(30);
});

test('User 工厂批量创建不会命中 mobile 唯一索引', function () {
    User::factory()->count(20)->create();

    expect(User::query()->distinct()->count('mobile'))->toBe(20);
});

test('Admin 工厂批量创建不会命中 mobile 唯一索引', function () {
    Admin::factory()->count(20)->create();

    expect(Admin::query()->distinct()->count('mobile'))->toBe(20);
});

test('UserLevel 工厂批量创建不会命中 code 和 name 唯一索引', function () {
    $levels = UserLevel::factory()->count(12)->create();

    expect($levels->unique('code'))->toHaveCount(12);
    expect($levels->unique('name'))->toHaveCount(12);
});
