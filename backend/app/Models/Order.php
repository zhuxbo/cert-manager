<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property-read Cert|null $latestCert
 * @property-read Cert[] $certs
 * @property-read User|null $user
 * @property-read Product $product
 * @property-read Notification $notifications
 * */
class Order extends BaseModel
{
    use HasFactory, HasSnowflakeId;

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
        'auto_reissue',
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
    ];

    /**
     * 获取 auto_renew 属性（保留 null）
     */
    public function getAutoRenewAttribute($value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    /**
     * 获取 auto_reissue 属性（保留 null）
     */
    public function getAutoReissueAttribute($value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

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

    /**
     * 获取验证文档
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class);
    }
}
