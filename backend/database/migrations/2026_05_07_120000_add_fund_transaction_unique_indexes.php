<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * funds + transactions 唯一索引（DB 物理阻断重复支付/重复入账）。
 *
 * 仅 mysql：
 * - funds(pay_method, pay_sn)：BTREE 唯一索引把 NULL 视为互不相等，
 * 处理中订单 (pay_sn=NULL) 不冲突，落地后才进入唯一性判定。
 * 旧 funds_type_pay_method_pay_sn_unique (type, pay_method, pay_sn) 语义偏弱
 * 且与新 2 列共存会让 MySQL 优先报旧索引名，先删旧再加新。
 * - transactions(type, transaction_id) WHERE type != 'order'：MySQL 8 不支持
 * partial index，用 generated VIRTUAL 列模拟（type='order' 时
 * dedup_key=NULL 不参与唯一约束）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropOldFundsTypePayMethodPaySnUnique();
        $this->addFundsPayMethodPaySnUnique();
        $this->addTransactionsDedupUnique();
    }

    public function down(): void
    {
        // 系统不需要支持回滚
    }

    private function addFundsPayMethodPaySnUnique(): void
    {
        $indexName = 'funds_pay_method_pay_sn_unique';

        if ($this->indexExists('funds', $indexName)) {
            return;
        }

        DB::statement('ALTER TABLE `funds` ADD UNIQUE KEY `'.$indexName.'` (`pay_method`, `pay_sn`)');
    }

    private function dropOldFundsTypePayMethodPaySnUnique(): void
    {
        $indexName = 'funds_type_pay_method_pay_sn_unique';

        if (! $this->indexExists('funds', $indexName)) {
            return;
        }

        DB::statement('ALTER TABLE `funds` DROP INDEX `'.$indexName.'`');
    }

    private function addTransactionsDedupUnique(): void
    {
        $indexName = 'transactions_dedup_unique';

        if ($this->indexExists('transactions', $indexName)) {
            return;
        }

        if (! Schema::hasColumn('transactions', 'dedup_key')) {
            DB::statement(
                'ALTER TABLE `transactions` ADD COLUMN `dedup_key` VARCHAR(64) '.
                "GENERATED ALWAYS AS (CASE WHEN `type` = 'order' THEN NULL ".
                "ELSE CONCAT(`type`, ':', `transaction_id`) END) VIRTUAL"
            );
        }
        DB::statement('ALTER TABLE `transactions` ADD UNIQUE KEY `'.$indexName.'` (`dedup_key`)');
    }

    private function indexExists(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getIndexes($table))->contains('name', $name);
    }
};
