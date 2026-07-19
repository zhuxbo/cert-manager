<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_logs')) {
            return;
        }

        $indexName = 'error_logs_exception_created_at_index';

        if (! collect(Schema::getIndexes('error_logs'))->contains('name', $indexName)) {
            return;
        }

        Schema::table('error_logs', function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
