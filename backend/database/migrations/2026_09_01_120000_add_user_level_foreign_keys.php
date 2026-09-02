<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_levels') || ! Schema::hasTable('users') || ! Schema::hasTable('product_prices')) {
            return;
        }

        $orphanBaseLevel = DB::table('users')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('user_levels')
                    ->whereColumn('user_levels.code', 'users.level_code');
            })
            ->value('level_code');

        if ($orphanBaseLevel !== null) {
            throw new RuntimeException("存在引用无效基础级别「{$orphanBaseLevel}」的用户，请先修复后重试迁移");
        }

        DB::table('user_levels')->insertOrIgnore([
            'code' => 'standard',
            'name' => '标准会员',
            'custom' => 0,
            'cost_rate' => 1,
            'weight' => 1,
        ]);

        DB::table('users')
            ->whereNotNull('custom_level_code')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('user_levels')
                    ->whereColumn('user_levels.code', 'users.custom_level_code');
            })
            ->update(['custom_level_code' => null]);

        DB::table('product_prices')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('user_levels')
                    ->whereColumn('user_levels.code', 'product_prices.level_code');
            })
            ->delete();

        if (! Schema::hasForeignKey('users', 'users_level_code_foreign')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('level_code')->references('code')->on('user_levels')->restrictOnDelete();
            });
        }

        if (! Schema::hasForeignKey('users', 'users_custom_level_code_foreign')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('custom_level_code')->references('code')->on('user_levels')->nullOnDelete();
            });
        }

        if (! Schema::hasForeignKey('product_prices', 'product_prices_level_code_foreign')) {
            Schema::table('product_prices', function (Blueprint $table) {
                $table->foreign('level_code')->references('code')->on('user_levels')->cascadeOnDelete();
            });
        }
    }
};
