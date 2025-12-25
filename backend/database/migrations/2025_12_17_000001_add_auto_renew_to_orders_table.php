<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'auto_renew')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('auto_renew')->default(false)->after('remark');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'auto_renew')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('auto_renew');
            });
        }
    }
};
