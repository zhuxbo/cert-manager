<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * error_logs 补 (exception, created_at) 复合索引（仅 mysql，普通 BTREE，5.7 + 8.x 通用）。
 *
 * Deploy 回调失败聚合（ApiController::recordCallbackFailure）按
 *   WHERE exception = 'DeployCallbackFailure' AND message LIKE 'order_id=X;%' AND created_at >= now()-7d
 * 做 7 天滑窗 count。原表 error_logs 仅有 created_at 单列索引（exception 无索引），只能按 created_at
 * 范围扫全部异常再逐行过滤 exception/message。exception 高选择性（DeployCallbackFailure 在异常日志里
 * 稀疏），复合 (exception, created_at) 让优化器直接 seek 到该异常 + 时间范围，message LIKE 降为小集残余
 * 过滤。既有 created_at 单列索引保留（LogsController 跨异常类型的 created_at 范围过滤仍用它），本索引为增量。
 *
 * 幂等：加索引前用 Schema::getIndexes() 按索引名判存（复用 add_missing_indexes 迁移的既有写法），
 * 整体升级可重入、既有生产库安全执行。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_logs')
            || ! Schema::hasColumn('error_logs', 'exception')
            || ! Schema::hasColumn('error_logs', 'created_at')) {
            return;
        }

        $indexName = 'error_logs_exception_created_at_index';

        if (collect(Schema::getIndexes('error_logs'))->contains('name', $indexName)) {
            return;
        }

        Schema::table('error_logs', function (Blueprint $table) use ($indexName) {
            $table->index(['exception', 'created_at'], $indexName);
        });
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
