<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auto_deploy_reports')) {
            Schema::create('auto_deploy_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary()->comment('ID');
                $table->unsignedBigInteger('order_id')->index()->comment('订单ID');
                $table->unsignedBigInteger('cert_id')->index()->comment('证书ID');
                $table->enum('status', ['success', 'failure'])->index()->comment('上报状态');
                $table->timestamp('deployed_at')->nullable()->index()->comment('部署时间');
                $table->string('ip', 100)->nullable()->comment('上报IP');
                $table->string('message', 500)->nullable()->comment('上报信息');
                $table->timestamp('created_at')->nullable()->index()->comment('上报时间');
            });
        }

        if (Schema::hasColumn('certs', 'auto_deploy_at')) {
            Schema::table('certs', function (Blueprint $table) {
                $table->dropColumn('auto_deploy_at');
            });
        }
    }
};
