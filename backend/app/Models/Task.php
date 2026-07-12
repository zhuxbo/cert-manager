<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends BaseModel
{
    use HasFactory;

    // 复合索引：(order_id, action, status)，收窄二级索引间隙锁范围，避免优化器退回
    // 单列 order_id 索引导致的宽 next-key lock → 1213 死锁。锁查询一律强制走它。
    public const TASK_LOCK_INDEX = 'tasks_order_action_status_index';

    protected $fillable = [
        'order_id',
        'action',
        'result',
        'attempts',
        'started_at',
        'last_execute_at',
        'source',
        'weight',
        'status',
    ];

    protected $casts = [
        'result' => 'json',
        'started_at' => 'datetime',
        'last_execute_at' => 'datetime',
        'weight' => 'integer',
    ];

    /**
     * 关联订单（order_id → orders.id）。
     *
     * 供 PurgeCommand 判定「关联订单是否仍为 pending 卡单」以保护其终态 task 不被按 90 天保留期清理
     * （否则失败 commit task 归零后到顶计数复位，卡单周期性复活重打上游 + 重发去重通知）。
     * 孤儿 task（order_id 无对应订单）不影响：whereDoesntHave 对无关联行返回真、照常清理。
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // 写入时设置 weight 等于 id
    public static function boot(): void
    {
        parent::boot();
        static::created(function ($model) {
            // 如果weight为0（使用了默认值），则更新为id
            if ($model->weight === 0) {
                $model->update(['weight' => $model->id]);
            }
        });
    }

    /**
     * 变更前锁定订单相关任务（FOR UPDATE）—— Order/ACME 变更事务共用
     *
     * 强制走复合索引 TASK_LOCK_INDEX 把间隙锁收窄到精确区间，配合统一锁顺序 task→order/acme 防死锁；
     * select('id') 只取主键，FOR UPDATE 锁的是索引扫描到的记录，与 select 列表无关，避免水合 TEXT result 列。
     * 调用形如 Task::lockForMutation($orderId, ['commit', 'sync'])->get();
     */
    public function scopeLockForMutation(Builder $query, int $orderId, array $actions): Builder
    {
        return $query
            ->forceIndex(self::TASK_LOCK_INDEX)
            ->where('order_id', $orderId)
            ->whereIn('action', $actions)
            ->whereIn('status', ['executing', 'stopped'])
            ->select('id')
            ->lockForUpdate();
    }
}
