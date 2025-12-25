<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('certs', 'auto_deploy_at')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->timestamp('auto_deploy_at')->nullable()->after('expires_at')->comment('自动部署时间');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('certs', 'auto_deploy_at')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->dropColumn('auto_deploy_at');
            });
        }
    }
};
