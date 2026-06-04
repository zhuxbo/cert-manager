<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补两批缺失索引（仅 mysql，普通 BTREE，5.7 + 8.x 通用）。
 *
 * - acmes：列表默认按 status 过滤（whereIn）+ created_at 范围 + product_id 反查（quickSearch / product_name），
 *   原表仅 PRIMARY/user_id/eab_kid/refer_id，缺这三个高频过滤列索引（参照 certs 的 status/created_at 惯例）。
 * - ca_logs / callback_logs / error_logs / user_logs：LogsController 对所有日志支持 created_at 范围过滤，
 *   但这四张表建表时 created_at 未建索引（api_logs / admin_logs 已有）。
 *
 * 幂等：加索引前用 Schema::getIndexes() 按索引名判存（复用 certs/correlation_id 迁移的既有写法）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // acmes：status / created_at / product_id
        $this->addIndex('acmes', 'status');
        $this->addIndex('acmes', 'created_at');
        $this->addIndex('acmes', 'product_id');

        // 日志表：created_at（api_logs / admin_logs 已在建表/历史迁移中加过，幂等跳过）
        foreach (['ca_logs', 'callback_logs', 'error_logs', 'user_logs'] as $table) {
            $this->addIndex($table, 'created_at');
        }
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }

    /**
     * 幂等加单列 BTREE 索引：表/列存在且同名索引不存在时才创建。
     * 索引名沿用 Laravel 默认 {table}_{column}_index。
     */
    private function addIndex(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $indexName = "{$table}_{$column}_index";

        if (collect(Schema::getIndexes($table))->contains('name', $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column) {
            $table->index($column);
        });
    }
};
