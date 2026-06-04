<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary()->comment('ID');
                $table->unsignedBigInteger('user_id')->comment('用户ID');
                $table->unsignedBigInteger('contact_id')->nullable()->index()->comment('联系人ID');
                $table->string('name', 200)->index()->comment('组织名称');
                $table->string('registration_number', 100)->nullable()->index()->comment('注册号');
                $table->string('country', 100)->nullable()->comment('国家');
                $table->string('state', 100)->nullable()->comment('省份');
                $table->string('city', 100)->nullable()->comment('城市');
                $table->string('address', 200)->nullable()->comment('地址');
                $table->string('postcode', 20)->nullable()->comment('邮编');
                $table->string('phone', 20)->nullable()->comment('电话');
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
