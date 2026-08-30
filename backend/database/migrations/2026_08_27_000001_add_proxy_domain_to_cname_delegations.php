<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cname_delegations', 'proxy_domain')) {
            Schema::table('cname_delegations', function (Blueprint $table) {
                $table->string('proxy_domain', 255)->nullable()->after('label')->comment('最近检测命中的委托代理域');
            });
        }

        if (! Schema::hasIndex('cname_delegations', 'cname_delegations_proxy_domain_index')) {
            Schema::table('cname_delegations', function (Blueprint $table) {
                $table->index('proxy_domain');
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
