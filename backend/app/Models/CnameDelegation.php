<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CNAME 委托模型
 *
 * @property int $id
 * @property int $user_id
 * @property string $zone
 * @property string $prefix
 * @property string $label
 * @property string|null $proxy_domain
 * @property string $target_fqdn (动态属性)
 * @property bool $valid
 * @property Carbon|null $last_checked_at
 * @property int $fail_count
 * @property string $last_error
 * @property-read User $user
 */
class CnameDelegation extends BaseModel
{
    use HasFactory, HasSnowflakeId;

    protected $fillable = [
        'user_id',
        'zone',
        'prefix',
        'label',
        'proxy_domain',
        'valid',
        'last_checked_at',
        'fail_count',
        'last_error',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'valid' => 'boolean',
        'fail_count' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    protected $hidden = [];

    protected $appends = ['target_fqdn'];

    /**
     * 获取所属用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /**
     * 动态获取 CNAME 目标（label.代理域名）
     *
     * @noinspection PhpUnused
     */
    protected function targetFqdn(): Attribute
    {
        return Attribute::make(
            get: function () {
                $proxyDomain = $this->proxy_domain;
                if (empty($proxyDomain)) {
                    return '';
                }

                return "$this->label.$proxyDomain";
            }
        );
    }
}
