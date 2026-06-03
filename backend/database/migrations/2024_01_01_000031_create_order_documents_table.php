<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_documents')) {
            Schema::create('order_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index()->comment('订单ID');
                $table->unsignedBigInteger('user_id')->index()->comment('用户ID');
                $table->string('type', 50)->comment('文档类型: APPLICANT/ORGANIZATION/AUTHORIZATION/ADDITIONAL');
                $table->string('file_name', 255)->comment('原始文件名');
                $table->string('file_path', 500)->comment('相对路径');
                $table->unsignedInteger('file_size')->comment('文件大小(bytes)');
                $table->string('content_hash', 64)->nullable()->comment('文件内容 sha256，用于跨级去重');
                $table->string('uploaded_by', 10)->comment('上传来源: user/admin/api');
                $table->unsignedTinyInteger('submitted')->default(0)->comment('是否已提交到上游');
                $table->timestamp('submitted_at')->nullable()->comment('提交上游成功时间');
                $table->unsignedTinyInteger('submit_attempts')->default(0)->comment('提交上游尝试次数');
                $table->string('submit_error', 255)->nullable()->comment('最后一次提交失败原因');
                $table->timestamps();

                $table->unique(['order_id', 'content_hash'], 'order_documents_dedup_unique');
            });
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }
};
