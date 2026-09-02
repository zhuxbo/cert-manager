<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class)->group('database');

test('基础级别孤儿使迁移失败时不改写已有数据', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $priceId = DB::table('product_prices')->insertGetId([
        'product_id' => $product->id,
        'level_code' => 'standard',
        'period' => 12,
        'price' => 99,
        'alternative_standard_price' => 0,
        'alternative_wildcard_price' => 0,
    ]);

    DB::table('user_levels')->where('code', 'standard')->update([
        'name' => '保留名称',
        'cost_rate' => 1.2345,
        'weight' => 77,
    ]);

    Schema::table('users', function (Blueprint $table) {
        $table->dropForeign('users_level_code_foreign');
        $table->dropForeign('users_custom_level_code_foreign');
    });
    Schema::table('product_prices', function (Blueprint $table) {
        $table->dropForeign('product_prices_level_code_foreign');
    });

    DB::table('users')->where('id', $user->id)->update([
        'level_code' => 'orphan-base',
        'custom_level_code' => 'orphan-custom',
    ]);
    DB::table('product_prices')->where('id', $priceId)->update(['level_code' => 'orphan-price']);

    $migration = require database_path('migrations/2026_09_01_120000_add_user_level_foreign_keys.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, '存在引用无效基础级别「orphan-base」的用户');

    $standard = DB::table('user_levels')->where('code', 'standard')->first();
    $failedUser = DB::table('users')->where('id', $user->id)->first();

    expect($standard->name)->toBe('保留名称')
        ->and((string) $standard->cost_rate)->toBe('1.2345')
        ->and((int) $standard->weight)->toBe(77)
        ->and($failedUser->custom_level_code)->toBe('orphan-custom')
        ->and(DB::table('product_prices')->where('id', $priceId)->value('level_code'))->toBe('orphan-price');

    DB::table('users')->where('id', $user->id)->update(['level_code' => 'standard']);
    $migration->up();

    $migratedStandard = DB::table('user_levels')->where('code', 'standard')->first();

    expect($migratedStandard->name)->toBe('保留名称')
        ->and((string) $migratedStandard->cost_rate)->toBe('1.2345')
        ->and((int) $migratedStandard->weight)->toBe(77)
        ->and(DB::table('users')->where('id', $user->id)->value('custom_level_code'))->toBeNull()
        ->and(DB::table('product_prices')->where('id', $priceId)->exists())->toBeFalse()
        ->and(Schema::hasForeignKey('users', 'users_level_code_foreign'))->toBeTrue()
        ->and(Schema::hasForeignKey('users', 'users_custom_level_code_foreign'))->toBeTrue()
        ->and(Schema::hasForeignKey('product_prices', 'product_prices_level_code_foreign'))->toBeTrue();
});
