<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cloud_deploy_logs')) {
            return;
        }

        Schema::create('cloud_deploy_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary()->comment('ID');
            $table->unsignedBigInteger('user_id')->index()->comment('用户ID');
            $table->unsignedBigInteger('target_id')->index()->comment('目标ID');
            $table->unsignedBigInteger('order_id')->index()->comment('订单ID');
            $table->unsignedBigInteger('cert_id')->comment('证书ID');
            // 快照字段（target/config 可变或被删，日志须自描述）
            $table->string('provider', 30)->index()->comment('快照: 云厂商');
            $table->string('product', 30)->index()->comment('快照: 云产品');
            $table->string('resource_summary')->nullable()->comment('快照: 资源摘要(域名等)');
            $table->string('access_name', 100)->nullable()->comment('快照: 凭证名');
            $table->string('trigger', 10)->comment('触发: auto/manual');
            $table->string('status', 10)->index()->comment('结果: success/failed');
            $table->unsignedInteger('attempt_no')->default(1)->comment('第几次尝试');
            $table->boolean('is_final')->default(false)->comment('是否最终结果');
            $table->string('remote_cert_id')->nullable()->comment('关联远端证书ID');
            $table->string('error_code', 50)->nullable()->comment('云API错误码');
            $table->text('message')->nullable()->comment('错误摘要(脱敏,不含私钥/AKSK)');
            $table->timestamp('deployed_at')->nullable()->comment('部署时间');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_deploy_logs');
    }
};
