<?php

namespace Plugins\Easy\Models;

use App\Models\BaseModel;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property int $id
 * @property string|null $pay_method
 * @property string|null $sign
 * @property int|null $type
 * @property array|null $data
 * @property string|null $tid
 * @property string|null $refund_id
 * @property string|null $status
 * @property string|null $product_code
 * @property int|null $period
 * @property string $price
 * @property int $count
 * @property string $amount
 * @property int|null $user_id
 * @property int|null $order_id
 * @property int $recharged
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Order|null $order
 * @property-read Cert|null $latestCert
 * @property-read Product|null $product
 */
class Agiso extends BaseModel
{
    protected $fillable = [
        'pay_method',
        'sign',
        'type',
        'data',
        'tid',
        'refund_id',
        'status',
        'product_code',
        'period',
        'price',
        'count',
        'amount',
        'user_id',
        'order_id',
        'recharged',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'price' => 'decimal:2',
        'data' => 'array',
        'count' => 'integer',
        'user_id' => 'integer',
        'order_id' => 'integer',
        'recharged' => 'integer',
        'type' => 'integer',
        'product_code' => 'string',
        'period' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function ($model) {
            $recharged = $model->getOriginal('recharged');

            if ($recharged === 1) {
                return false;
            }

            return true;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function latestCert(): HasOneThrough
    {
        return $this->hasOneThrough(
            Cert::class,
            Order::class,
            'id',
            'id',
            'order_id',
            'latest_cert_id'
        );
    }

    public function product(): HasOneThrough
    {
        return $this->hasOneThrough(
            Product::class,
            Order::class,
            'id',
            'id',
            'order_id',
            'product_id'
        );
    }

    /**
     * 将外部平台代码转换为 pay_method 值
     */
    public static function platformToPayMethod(string $platform): string
    {
        $normalizedPlatform = strtolower(trim($platform));

        $map = [
            'tbalds' => 'taobao',
            'taobao' => 'taobao',
            'pddalds' => 'pinduoduo',
            'pinduoduo' => 'pinduoduo',
            'aldsjd' => 'jingdong',
            'jingdong' => 'jingdong',
            'aldsdoudian' => 'douyin',
            'douyin' => 'douyin',
            'gift' => 'gift',
        ];

        return $map[$normalizedPlatform] ?? 'other';
    }
}
