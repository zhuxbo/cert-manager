<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['api_logs', 'callback_logs', 'error_logs'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'module')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('module', 100)->nullable()->after('id')->comment('模块');
                });
            }

            if (! Schema::hasColumn($tableName, 'action')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('action', 100)->nullable()->after('module')->comment('操作');
                });
            }

            $indexName = $tableName.'_module_action_created_at_index';
            if (! Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->index(['module', 'action', 'created_at'], $indexName);
                });
            }
        }
    }
};
