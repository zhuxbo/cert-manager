<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 添加 created_at 索引
        if (! collect(Schema::getIndexes('transactions'))->contains('name', 'transactions_created_at_index')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->index('created_at');
            });
        }

        // 修改 type 枚举，添加 acme 类型
        $typeCol = collect(Schema::getColumns('transactions'))->firstWhere('name', 'type');
        if ($typeCol && ! str_contains($typeCol['type'], "'acme_order'")) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->enum('type', [
                    'order', 'cancel', 'addfunds', 'refunds', 'deduct', 'reverse', 'acme_order', 'acme_cancel',
                ])->comment('交易类型')->change();
            });
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }
};
