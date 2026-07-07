<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cloud_deploy_targets')) {
            return;
        }

        Schema::create('cloud_deploy_targets', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary()->comment('ID');
            $table->unsignedBigInteger('user_id')->index()->comment('用户ID');
            $table->unsignedBigInteger('access_id')->index()->comment('凭证ID');
            $table->unsignedBigInteger('order_id')->index()->comment('绑定订单ID');
            $table->string('product', 30)->index()->comment('云产品: cdn/oss/clb/waf/...');
            $table->text('config')->comment('资源参数 JSON: 域名/region/实例ID 等');
            $table->char('config_hash', 64)->comment('规范化 config SHA-256');
            $table->boolean('enabled')->default(true)->comment('是否启用自动推送');
            $table->unsignedBigInteger('last_cert_id')->nullable()->comment('最近成功推送的证书ID(幂等键)');
            $table->string('last_status', 20)->nullable()->index()->comment('最近尝试结果: success/failed');
            $table->text('last_error')->nullable()->comment('最近尝试错误摘要');
            $table->timestamp('last_deployed_at')->nullable()->index()->comment('最近尝试时间');
            $table->timestamps();
            $table->unique(['user_id', 'access_id', 'product', 'config_hash'], 'cloud_deploy_targets_unique_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_deploy_targets');
    }
};
