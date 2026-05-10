<?php

namespace App\Models;

use App\Models\Traits\HasReadOnlyFields;
use App\Models\Traits\HasSnowflakeId;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
     * 创建 transaction 记录。
     *
     * 调用方必须在 DB::transaction 内，否则 Transaction::create 修改的 balance +
     * transactions INSERT 与外层 funds 操作无法原子提交。
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

    /**
     * CAS 状态转换：Fund.status 0→1 原子化。
     *
     * 完整 5 字段 WHERE（id + amount + type + pay_method + status=0），
     * affected_rows=1 才认成功。CAS WHERE 不能简化 —— 否则金额或支付方式不匹配
     * 的回调会把本地 fund 标成功并按本地 amount 入账。
     *
     * 调用契约：必须在 DB::transaction 内调用，使 CAS UPDATE + Transaction::create
     * + user.balance 修改原子提交。
     *
     * @return self|null CAS 成功返回 fund 实例；任一字段不匹配或被并发抢占返回 null
     */
    public static function transitionToSuccessful(
        int|string $id,
        string $expectedAmount,
        string $expectedType,
        string $expectedPayMethod,
        string $paySn
    ): ?self {
        if (DB::transactionLevel() === 0) {
            throw new Exception('Fund::transitionToSuccessful 必须在 DB::transaction 内调用（防止 fund 状态与 transaction 非原子）');
        }

        $updated = static::where('id', $id)
            ->where('amount', $expectedAmount)
            ->where('type', $expectedType)
            ->where('pay_method', $expectedPayMethod)
            ->where('status', 0)
            ->update(['status' => 1, 'pay_sn' => $paySn]);

        if ($updated !== 1) {
            return null;
        }

        $fund = static::find($id);
        if (! $fund) {
            return null;
        }

        // 查询构建器 update() 不触发 updating 钩子，CAS 成功后显式补写 transaction
        $transaction = self::getTypeAmount($fund, ['transaction_id' => $fund->id]);

        Transaction::create($transaction);

        return $fund;
    }
}
