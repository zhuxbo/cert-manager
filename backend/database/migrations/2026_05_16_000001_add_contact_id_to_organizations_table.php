<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        if (! Schema::hasColumn('organizations', 'contact_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('contact_id')->nullable()->after('user_id')->index();
            });
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }
};
