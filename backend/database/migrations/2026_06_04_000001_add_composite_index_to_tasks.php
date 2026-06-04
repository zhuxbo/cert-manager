<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        // 幂等：复合索引已存在则跳过（整体升级可重入；SHOW INDEX 在 MySQL 5.7/8.x 均支持）
        $exists = ! empty(DB::select(
            "SHOW INDEX FROM `tasks` WHERE Key_name = 'tasks_order_action_status_index'"
        ));

        if (! $exists) {
            // 覆盖 sync/checkRepeat/createTask/batchCommitCancel/refundForSyncedCancel 的
            // (order_id, action, status) FOR UPDATE 查询，把二级索引间隙锁范围从"整个 order_id 区间"
            // 收窄到精确区间，消除 sync×commit 跨 action 抢同一批 task 行的 InnoDB 死锁
            Schema::table('tasks', function (Blueprint $table) {
                $table->index(['order_id', 'action', 'status'], 'tasks_order_action_status_index');
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
