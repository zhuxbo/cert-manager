<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 填充 NULL 值为空数组
        DB::table('products')->whereNull('alternative_name_types')->update(['alternative_name_types' => '[]']);

        // 修改 alternative_name_types 为 NOT NULL DEFAULT '[]'
        $altCol = collect(Schema::getColumns('products'))->firstWhere('name', 'alternative_name_types');
        if ($altCol && $altCol['nullable']) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('alternative_name_types', 50)->default('[]')->comment('备用名称类型')->change();
            });
        }

        // 修改 product_type 枚举添加 'acme'
        $ptCol = collect(Schema::getColumns('products'))->firstWhere('name', 'product_type');
        if ($ptCol && ! str_contains($ptCol['type'], "'acme'")) {
            Schema::table('products', function (Blueprint $table) {
                $table->enum('product_type', ['ssl', 'codesign', 'smime', 'docsign', 'acme'])
                    ->default('ssl')
                    ->comment('产品类型')
                    ->change();
            });
        }

        // 删除废弃的 support_acme 列
        if (Schema::hasColumn('products', 'support_acme')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('support_acme');
            });
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }
};
