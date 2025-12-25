<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property-read Cert $latestCert
 * @property-read Cert[] $certs
 * @property-read User $user
 * @property-read Product $product
 * @property-read Notification $notifications
 * */
class Order extends BaseModel
{
    use HasSnowflakeId;

    protected $fillable = [
        'user_id',
        'product_id',
        'latest_cert_id',
        'brand',
        'period',
        'plus',
        'amount',
        'period_from',
        'period_till',
        'purchased_standard_count',
        'purchased_wildcard_count',
        'organization',
        'contact',
        'cancelled_at',
        'admin_remark',
        'remark',
        'auto_renew',
    ];

    protected $casts = [
        'period' => 'integer',
        'plus' => 'integer',
        'amount' => 'decimal:2',
        'organization' => 'json',
        'contact' => 'json',
        'period_from' => 'datetime',
        'period_till' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    /**
     * 获取用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /**
     * 获取产品
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 获取证书
     */
    public function certs(): HasMany
    {
        return $this->hasMany(Cert::class);
    }

    /**
     * 获取最新证书
     */
    public function latestCert(): BelongsTo
    {
        return $this->belongsTo(Cert::class, 'latest_cert_id');
    }

    /**
     * 获取通知
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }
}
