<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table) {
                $table->id();
                // order_id 不建单列索引：与下方复合索引同首列（左前缀覆盖），
                // 单列索引体积更小会被优化器退回、扩大间隙锁范围 → 1213 死锁（见 2026_07_08 drop 迁移）
                $table->unsignedBigInteger('order_id')->comment('订单ID');
                $table->string('action', 50)->index()->comment('任务动作');
                $table->text('result')->nullable()->comment('执行结果');
                $table->integer('attempts')->default(0)->comment('执行次数');
                $table->timestamp('started_at')->nullable()->comment('开始执行时间');
                $table->timestamp('last_execute_at')->nullable()->comment('最后执行时间');
                $table->string('source', 50)->nullable()->comment('来源');
                $table->integer('weight')->default(0)->comment('权重');
                $table->enum('status', ['executing', 'successful', 'failed', 'stopped'])
                    ->default('executing')
                    ->index()
                    ->comment('状态:executing=待执行,successful=已成功,failed=已失败,stopped=已停止');
                $table->timestamps();

                // 复合索引：覆盖 sync/checkRepeat/createTask 的 (order_id, action, status) FOR UPDATE 查询，
                // 收窄二级索引间隙锁范围，消除 sync×commit 跨 action 抢同一批 task 行的 InnoDB 死锁
                $table->index(['order_id', 'action', 'status'], 'tasks_order_action_status_index');
            });
        }
    }

    public function down(): void
    {
        // 系统采用整体升级方式，不支持回滚操作
    }
};
