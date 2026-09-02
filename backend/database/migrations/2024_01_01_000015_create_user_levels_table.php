<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_levels')) {
            Schema::create('user_levels', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement()->comment('ID');
                $table->string('name', 100)->unique()->comment('级别名称');
                $table->string('code', 50)->unique()->comment('级别标识');
                $table->unsignedTinyInteger('custom')->default(0)->index()->comment('定制: 0=否, 1=是');
                $table->decimal('cost_rate', 6, 4)->default(1.0000)->comment('成本价倍率');
                $table->integer('weight')->default(0)->index()->comment('权重');
                $table->timestamps();
            });
        }

        DB::table('user_levels')->insertOrIgnore([
            'code' => 'standard',
            'name' => '标准会员',
            'custom' => 0,
            'cost_rate' => 1,
            'weight' => 1,
        ]);

        if (Schema::hasTable('users') && ! Schema::hasForeignKey('users', 'users_level_code_foreign')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('level_code')->references('code')->on('user_levels')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasForeignKey('users', 'users_custom_level_code_foreign')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('custom_level_code')->references('code')->on('user_levels')->nullOnDelete();
            });
        }

        if (Schema::hasTable('product_prices') && ! Schema::hasForeignKey('product_prices', 'product_prices_level_code_foreign')) {
            Schema::table('product_prices', function (Blueprint $table) {
                $table->foreign('level_code')->references('code')->on('user_levels')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
