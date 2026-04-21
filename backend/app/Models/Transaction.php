<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Transaction extends BaseModel
{
    use HasFactory;
    use HasSnowflakeId;

    // Laravel 不更新时间戳
    public const null UPDATED_AT = null;

    const string TYPE_ACME_ORDER = 'acme_order';

    const string TYPE_ACME_CANCEL = 'acme_cancel';

    protected $fillable = [
        'user_id',
        'type',
        'transaction_id',
        'amount',
        'standard_count',
        'wildcard_count',
        'balance_before',
        'balance_after',
        'remark',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /**
     * 模型的"启动"方法
     */
    protected static function boot(): void
    {
        parent::boot();

        // 创建前事件
        //
        // 契约：调用方必须在 DB::transaction 内调用 Transaction::create()，
        // 否则 balance 修改与 transactions INSERT 之间无原子性保证（INSERT 失败会留下脏余额）。
        // 本钩子不再开自己的内层事务/savepoint —— 让错误冒泡给外层事务统一回滚。
        static::creating(function ($model) {
            if (bccomp((string) $model->amount, '0.00', 2) === 0) {
                return false;
            }

            if (DB::transactionLevel() === 0) {
                throw new Exception('Transaction::create 必须在 DB::transaction 内调用（防止 balance 修改与 INSERT 非原子）');
            }

            // order 和 acme_order 允许重复 transaction_id（重签增加域名会再次扣费）
            if (! in_array($model->type, ['order', 'acme_order'])) {
                $exists = self::where(['type' => $model->type, 'transaction_id' => $model->transaction_id])->exists();
                if ($exists) {
                    throw new Exception('交易记录已存在');
                }
            }

            $user = User::where('id', $model->user_id)->lockForUpdate()->first();
            if (! $user) {
                throw new Exception('用户不存在');
            }

            $model->balance_before = $user->balance;

            $user->balance = bcadd((string) $user->balance, (string) $model->amount, 2);
            $user->save();

            $model->balance_after = $user->balance;

            return true;
        });

        // 禁止更新
        static::updating(function () {
            return false;
        });

        // 禁止删除
        static::deleting(function () {
            return false;
        });
    }
}
