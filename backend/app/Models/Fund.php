<?php

namespace App\Models;

use App\Models\Traits\HasReadOnlyFields;
use App\Models\Traits\HasSnowflakeId;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fund extends BaseModel
{
    use HasFactory;
    use HasReadOnlyFields;
    use HasSnowflakeId;

    // 只读字段
    protected array $readOnlyFields = ['id', 'user_id', 'amount', 'pay_method', 'ip'];

    protected $fillable = [
        'user_id',
        'amount',
        'type',
        'pay_method',
        'pay_sn',
        'ip',
        'remark',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => 'integer',
    ];

    /**
     * 获取用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /**
     * 设置支付流水号
     */
    public function setPaySnAttribute(string|int|null $value): void
    {
        $this->attributes['pay_sn'] = $value ?: null;
    }

    /**
     * 模型的"启动"方法
     */
    protected static function boot(): void
    {
        parent::boot();

        // 创建前事件
        static::creating(function ($model) {
            // 状态为2时不允许创建
            if ($model->status == 2) {
                throw new Exception('已退状态不允许创建');
            }

            $model->ip = request()->ip();

            // 如果序列号不为空 检查 type pay_method pa_sn 组合唯一
            if ($model->pay_sn) {
                $exists = self::where('pay_sn', $model->pay_sn)
                    ->where('type', $model->type)
                    ->where('pay_method', $model->pay_method)
                    ->exists();

                if ($exists) {
                    throw new Exception('支付编号重复');
                }
            }

            if ($model->status == 1) {
                self::createRecord($model);
            }

            return true;
        });

        // 更新前事件
        static::updating(function ($model) {
            $originStatus = $model->getOriginal('status');
            if ($originStatus == 2 || ($originStatus == 1 && $model->status == 0) || ($originStatus == 0 && $model->status == 2)) {
                $model->status = $originStatus;
            }

            if ($originStatus == 0 && $model->status == 1) {
                self::createRecord($model);
            }

            if ($originStatus == 1 && $model->status == 2) {
                self::createRecord($model);
            }

            return true;
        });

        // 删除前事件
        static::deleting(function ($model) {
            $status = $model->getOriginal('status');
            $created_at = $model->getOriginal('created_at');

            if ($status !== 0) {
                return false;
            }

            // 处理中订单2小时内不允许删除
            if (strtotime($created_at) > strtotime('-2 hours')) {
                return false;
            }

            return true;
        });
    }

    /**
     * 创建 transaction 记录
     *
     * 契约：调用方（Fund::saving 钩子触发路径）必须处于 DB::transaction 内，
     * 否则 Transaction::create 修改的 user.balance + transactions INSERT 与外层 funds INSERT/UPDATE
     * 无法原子提交 —— 后续 funds 插入失败会留下"余额与 transaction 已落库，funds 记录缺失"的脏状态。
     * 本方法不再开自己的内层事务/savepoint，让错误冒泡给外层事务统一回滚。
     * Transaction::creating 钩子会在 transactionLevel=0 时抛异常兜底。
     */
    private static function createRecord(Model $model): void
    {
        /** @var \App\Models\Fund $model */
        $transaction['transaction_id'] = $model->id;
        $transaction = self::getTypeAmount($model, $transaction);

        Transaction::create($transaction);
    }

    private static function getTypeAmount($model, array $data): array
    {
        $data['user_id'] = $model->user_id;
        $data['type'] = $model->getAttribute('type');

        $amount = abs(floatval($model->amount));
        $model->amount = number_format($amount, 2, '.', '');

        if ($data['type'] == 'addfunds' || $data['type'] == 'reverse') {
            $data['amount'] = $model->amount;
        } else {
            $data['amount'] = '-'.$model->amount;
        }

        return $data;
    }
}
