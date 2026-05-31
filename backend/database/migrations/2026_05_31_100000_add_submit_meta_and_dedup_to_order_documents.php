<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * order_documents 增补：
     * - 异步提交上游的进度/结果列：submitted_at / submit_attempts / submit_error
     *   （submitDocuments 改 SubmitDocumentJob 队列重试后，供前端轮询展示进度与失败原因）
     * - 跨级去重键 content_hash（文件内容 sha256 hex）+ 唯一索引 (order_id, content_hash)
     *   接收端按内容去重，防止 SubmitDocumentJob 重试时在上游产生重复文档行 → Certum 重复提交
     *   content_hash 可空：旧行为 NULL，MySQL 唯一索引下多个 NULL 互不冲突，不影响存量数据
     */
    public function up(): void
    {
        if (! Schema::hasTable('order_documents')) {
            return;
        }

        Schema::table('order_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('order_documents', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted')->comment('提交上游成功时间');
            }
            if (! Schema::hasColumn('order_documents', 'submit_attempts')) {
                $table->unsignedTinyInteger('submit_attempts')->default(0)->after('submitted_at')->comment('提交上游尝试次数');
            }
            if (! Schema::hasColumn('order_documents', 'submit_error')) {
                $table->string('submit_error', 255)->nullable()->after('submit_attempts')->comment('最后一次提交失败原因');
            }
            if (! Schema::hasColumn('order_documents', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->after('file_size')->comment('文件内容 sha256，用于跨级去重');
            }
        });

        $indexName = 'order_documents_dedup_unique';
        $hasIndex = collect(Schema::getIndexes('order_documents'))->contains('name', $indexName);
        if (! $hasIndex) {
            Schema::table('order_documents', function (Blueprint $table) use ($indexName) {
                $table->unique(['order_id', 'content_hash'], $indexName);
            });
        }
    }

    public function down(): void
    {
        // 系统不支持回滚
    }
};
