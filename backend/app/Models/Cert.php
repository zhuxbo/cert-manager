<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read Cert|null $lastCert  上一个证书（本证书由其续费/重签而来）
 * @property-read Cert|null $nextCert  接替证书（其 last_cert_id 指向本证书）
 */
class Cert extends BaseModel
{
    use HasFactory, HasSnowflakeId;

    /**
     * 缓存的中间证书（避免重复查询）
     */
    private ?string $cachedIntermediateCert = null;

    /**
     * 使用 bool 标记 避免查询到 null 时重复查询
     */
    private bool $intermediateCertCached = false;

    /**
     * 国密(SM2)加密三元组列名：加密证书 / 加密私钥 / 加密私钥(GMT-0009)。
     * 单一来源，避免在 fillable / V2 get select 白名单 / sync 终态守卫等多处硬编码漂移。
     */
    public const ENC_FIELDS = ['enc_cert', 'enc_key', 'enc_key2'];

    protected $fillable = [
        'order_id',
        'last_cert_id',
        'api_id',
        'vendor_id',
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
        ...self::ENC_FIELDS,
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
            $model->csr_md5 = md5($model->csr ?? '');
            if (empty($model->refer_id)) {
                $model->refer_id = bin2hex(random_bytes(16));
            }
        });

        // 模型检索后的事件：检查中间证书状态并缓存查询结果
        static::retrieved(function ($model) {
            // 如果订单已签发且有 issuer，预查询中间证书（只查询一次）
            if ($model->status === 'active' && ! empty($model->issuer)) {
                $model->cachedIntermediateCert = self::chainMap()[$model->issuer] ?? null;
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
     * 获取接替证书（续费/重签产生的下一张证书，其 last_cert_id 指向本证书）。
     *
     * certs.last_cert_id 为 nullable UNIQUE → 至多一条接替，故 HasOne。与 lastCert() BelongsTo 同键对称，
     * 供续期停滞检测（StalledRenewalQuery）单点表达「存在接替」，避免多处裸 whereExists 拼写漂移。
     */
    public function nextCert(): HasOne
    {
        return $this->hasOne(self::class, 'last_cert_id');
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

        // 首次访问时从请求级缓存取，并缓存到实例
        $this->cachedIntermediateCert = self::chainMap()[$this->issuer] ?? null;
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
            // 写入新中间证书后让请求级缓存失效，确保同请求后续读取反映最新数据
            app()->forgetInstance('cert.chainMap');
        }
    }

    /**
     * 请求 / Job 级缓存全部中间证书（issuer => intermediate_cert）。
     *
     * Chain 表存 CA 中间证书（common_name 唯一、issuer 数量有限），一次性加载到容器，避免列表场景
     * 每条 active cert 各查一次 Chain（N+1）。retrieved 钩子早于 with() eager load 触发，无法用
     * 预加载关联，故用此缓存替代逐条查询。
     *
     * 用 scoped 而非 instance：FPM 每请求新容器天然刷新；queue:work 常驻 worker 在每个 job 边界由框架
     * resetScope → forgetScopedInstances 自动清理 scoped 绑定，避免跨 job 读到陈旧中间证书（instance
     * 不在 scopedInstances 中、不会随 job 清理）。setIntermediateCert 写入新 Chain 后 forgetInstance
     * 让同请求 / 同 job 内即时失效。
     */
    protected static function chainMap(): array
    {
        if (! app()->bound('cert.chainMap')) {
            app()->scoped('cert.chainMap', fn () => Chain::pluck('intermediate_cert', 'common_name')->all());
        }

        return app('cert.chainMap');
    }
}
