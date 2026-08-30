<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cname_delegations')) {
            Schema::create('cname_delegations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index()->comment('所有者用户ID');

                $table->string('zone', 255)->index()->comment('委托域(ACME: 必须具体FQDN; 其他前缀: 支持根域或子域)');
                $table->string('prefix', 50)->index()->comment('委托前缀');

                $table->string('label', 64)->comment('SHA256哈希标签(64位hex)');
                $table->string('proxy_domain', 255)->nullable()->index()->comment('最近检测命中的委托代理域');

                $table->boolean('valid')->default(false)->index()->comment('委托是否有效(健康检查结果)');
                $table->timestamp('last_checked_at')->nullable()->comment('上次健康检查时间');
                $table->unsignedTinyInteger('fail_count')->default(0)->comment('连续失败次数(用于熔断/预警)');
                $table->string('last_error', 255)->default('')->comment('最近一次检查失败原因');

                $table->timestamps();

                // 唯一索引: 一个用户对于同一个域名和前缀只能有一条委托记录
                $table->unique(['user_id', 'zone', 'prefix'], 'uniq_user_zone_prefix');
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
