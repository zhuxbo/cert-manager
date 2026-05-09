<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 6 张日志表添加 correlation_id 列 + 单列索引，
     * 用于 HTTP / Job / outbound / exception 全链路跨表追踪。
     *
     * - varchar(40)：UUID v4（36 字符）+ 兜底自定义短码 / 哈希前缀（≤40）
     * - nullable：保持向前兼容，旧数据不需要回填
     * - 单列索引：客服按 correlation_id 拉链路；UNION 6 表查询走索引
     */
    public function up(): void
    {
        $tables = ['admin_logs', 'user_logs', 'api_logs', 'callback_logs', 'ca_logs', 'error_logs'];

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'correlation_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('correlation_id', 40)
                        ->nullable()
                        ->after('id')
                        ->comment('跨表追踪 ID');
                });
            }

            // 索引名固定为 {table}_correlation_id_index，避免重复创建
            $indexName = "{$tableName}_correlation_id_index";
            $hasIndex = collect(Schema::getIndexes($tableName))
                ->contains('name', $indexName);

            if (! $hasIndex) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->index('correlation_id');
                });
            }
        }
    }

    public function down(): void
    {
        // 系统不支持回滚
    }
};
