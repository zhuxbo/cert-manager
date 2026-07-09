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

        // 删除与复合索引 tasks_order_action_status_index 同首列的孪生单列索引 tasks_order_id_index：
        // 单列索引体积更小，是优化器退回的现实目标，会把二级索引间隙锁扩大到整个 order_id 区间 → 跨 action
        // 抢同一批 task 行时 InnoDB 1213 死锁；复合索引左前缀完全覆盖 order_id 查询，删除后全部查询走复合索引。
        // 幂等（整体升级可重入，SHOW INDEX 在 MySQL 5.7/8.x/MariaDB 均支持）：
        // 仅在孪生索引存在、且复合索引已就位（兜底左前缀覆盖后再删）时才删。
        $twinExists = ! empty(DB::select(
            "SHOW INDEX FROM `tasks` WHERE Key_name = 'tasks_order_id_index'"
        ));
        $compositeExists = ! empty(DB::select(
            "SHOW INDEX FROM `tasks` WHERE Key_name = 'tasks_order_action_status_index'"
        ));

        if ($twinExists && $compositeExists) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropIndex('tasks_order_id_index');
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
