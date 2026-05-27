<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $table) {
                $table->unsignedInteger('id')->autoIncrement()->comment('ID');
                $table->string('name', 100)->index()->comment('模板名称');
                $table->string('code', 50)->index()->comment('模板标识');
                $table->text('content')->nullable()->comment('模板内容');
                $table->text('variables')->nullable()->comment('变量说明');
                $table->text('example')->nullable()->comment('示例');
                $table->unsignedTinyInteger('status')->default(1)->index()->comment('状态:1=启用,0=禁用');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
