<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cloud_deploy_accesses')) {
            return;
        }

        Schema::create('cloud_deploy_accesses', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary()->comment('ID');
            $table->unsignedBigInteger('user_id')->index()->comment('用户ID');
            $table->string('name', 100)->comment('备注名');
            $table->string('provider', 30)->index()->comment('云厂商: aliyun/tencent/...');
            $table->text('credentials')->comment('加密的 AK/SK JSON');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_deploy_accesses');
    }
};
