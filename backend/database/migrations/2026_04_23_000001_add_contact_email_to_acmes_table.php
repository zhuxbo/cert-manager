<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acmes')) {
            return;
        }

        if (! Schema::hasColumn('acmes', 'contact_email')) {
            Schema::table('acmes', function (Blueprint $table) {
                $table->string('contact_email', 254)->nullable()->after('vendor_id')->comment('ACME 账号邮箱（RFC 8555 contact）');
            });
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }
};
