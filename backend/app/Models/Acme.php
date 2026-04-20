<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read User $user
 * @property-read Product $product
 */
class Acme extends BaseModel
{
    use HasFactory, HasSnowflakeId;

    protected $table = 'acmes';

    const string STATUS_UNPAID = 'unpaid';

    const string STATUS_PENDING = 'pending';

    const string STATUS_ACTIVE = 'active';

    const string STATUS_CANCELLING = 'cancelling';

    const string STATUS_CANCELLED = 'cancelled';

    const string STATUS_REVOKED = 'revoked';

    const string STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'product_id',
        'brand',
        'period',
        'plus',
        'purchased_standard_count',
        'purchased_wildcard_count',
        'refer_id',
        'api_id',
        'vendor_id',
        'eab_kid',
        'eab_hmac',
        'period_from',
        'period_till',
        'cancelled_at',
        'status',
        'channel',
        'remark',
        'amount',
        'admin_remark',
    ];

    protected $hidden = [
        'eab_hmac',
    ];

    protected $casts = [
        'eab_hmac' => 'encrypted',
        'period' => 'integer',
        'plus' => 'integer',
        'purchased_standard_count' => 'integer',
        'purchased_wildcard_count' => 'integer',
        'amount' => 'decimal:2',
        'period_from' => 'datetime',
        'period_till' => 'datetime',
        'cancelled_at' => 'datetime',
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
}
