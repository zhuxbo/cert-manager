<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cert extends BaseModel
{
    use HasSnowflakeId;

    /**
     * 缓存的中间证书（避免重复查询）
     */
    private ?string $cachedIntermediateCert = null;

    /**
     * 使用 bool 标记 避免查询到 null 时重复查询
     */
    private bool $intermediateCertCached = false;

    protected $fillable = [
        'order_id',
        'last_cert_id',
        'api_id',
        'vendor_id',
        'vendor_cert_id',
        'refer_id',
        'unique_value',
        'issuer',
        'action',
        'channel',
        'params',
        'amount',
        'common_name',
        'alternative_names',
        'email',
        'standard_count',
        'wildcard_count',
        'dcv',
        'validation',
        'documents',
        'issued_at',
        'expires_at',
        'csr_md5',
        'csr',
        'private_key',
        'cert',
        'intermediate_cert',
        'serial_number',
        'fingerprint',
        'encryption_alg',
        'encryption_bits',
        'signature_digest_alg',
        'cert_apply_status',
        'domain_verify_status',
        'org_verify_status',
        'status',
        'auto_deploy_at',
    ];

    protected $casts = [
        'params' => 'json',
        'dcv' => 'json',
        'validation' => 'json',
        'documents' => 'json',
        'amount' => 'decimal:2',
        'standard_count' => 'integer',
        'wildcard_count' => 'integer',
        'encryption_bits' => 'integer',
        'cert_apply_status' => 'integer',
        'domain_verify_status' => 'integer',
        'org_verify_status' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_deploy_at' => 'datetime',
    ];

    protected $appends = ['intermediate_cert'];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->csr_md5 = md5($model->csr);
        });

        // 模型检索后的事件：检查中间证书状态并缓存查询结果
        static::retrieved(function ($model) {
            // 如果订单已签发且有 issuer，预查询中间证书（只查询一次）
            if ($model->status === 'active' && ! empty($model->issuer)) {
                $model->cachedIntermediateCert = Chain::where('common_name', $model->issuer)->value('intermediate_cert');
                $model->intermediateCertCached = true;

                // 如果中间证书不存在，则设置状态为 approving 等待下次同步获取
                if (empty($model->cachedIntermediateCert)) {
                    $model->attributes['status'] = 'approving';
                }
            }
        });
    }

    /**
     * 获取订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * 获取上一个证书
     */
    public function lastCert(): BelongsTo
    {
        return $this->belongsTo(self::class, 'last_cert_id');
    }

    /**
     * 获取中间证书
     */
    public function getIntermediateCertAttribute(): ?string
    {
        if (empty($this->issuer)) {
            return null;
        }

        // 如果已经缓存过，直接返回缓存结果（避免重复查询）
        if ($this->intermediateCertCached) {
            return $this->cachedIntermediateCert;
        }

        // 首次访问时查询并缓存
        $this->cachedIntermediateCert = Chain::where('common_name', $this->issuer)->value('intermediate_cert');
        $this->intermediateCertCached = true;

        return $this->cachedIntermediateCert;
    }

    /**
     * 设置中间证书
     */
    public function setIntermediateCertAttribute(?string $value): void
    {
        if (empty($this->issuer) || empty($value)) {
            return;
        }

        $chain = Chain::where('common_name', $this->issuer)->first();

        if (! $chain) {
            Chain::create([
                'common_name' => $this->issuer,
                'intermediate_cert' => $value,
            ]);
        }
    }
}
