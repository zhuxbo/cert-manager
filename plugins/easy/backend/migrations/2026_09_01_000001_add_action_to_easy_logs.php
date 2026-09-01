<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('easy_logs')) {
            return;
        }

        if (! Schema::hasColumn('easy_logs', 'action')) {
            Schema::table('easy_logs', function (Blueprint $table) {
                $table->string('action', 100)->nullable()->after('id');
            });
        }

        if (! Schema::hasIndex('easy_logs', 'easy_logs_created_at_index')) {
            Schema::table('easy_logs', function (Blueprint $table) {
                $table->index('created_at', 'easy_logs_created_at_index');
            });
        }

        if (! Schema::hasIndex('easy_logs', 'easy_logs_action_created_at_index')) {
            Schema::table('easy_logs', function (Blueprint $table) {
                $table->index(['action', 'created_at'], 'easy_logs_action_created_at_index');
            });
        }
    }
};
