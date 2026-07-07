<?php

namespace Plugins\CloudDeploy\Models;

use App\Models\BaseModel;
use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 云部署目标（订单 × 凭证 × 云产品）。属性对应 cloud_deploy_targets 表列，供静态分析解析 Eloquent 魔术属性。
 *
 * @property int $id
 * @property int $user_id
 * @property int $access_id
 * @property int $order_id
 * @property string $product 云产品: cdn/oss/clb/waf/...
 * @property array $config array cast（资源参数：域名/region/实例ID 等）
 * @property string $config_hash 规范化 config 的 SHA-256，用于同一推送目标唯一约束
 * @property bool $enabled
 * @property int|null $last_cert_id 最近成功推送的证书ID（幂等键）
 * @property string|null $last_status 最近尝试结果: success/failed
 * @property string|null $last_error
 * @property Carbon|null $last_deployed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CloudDeployAccess|null $access
 * @property-read \App\Models\Order|null $order
 */
class CloudDeployTarget extends BaseModel
{
    use HasSnowflakeId;

    protected $table = 'cloud_deploy_targets';

    protected $fillable = [
        'user_id', 'access_id', 'order_id', 'product', 'config', 'config_hash',
        'enabled', 'last_cert_id', 'last_status', 'last_error', 'last_deployed_at',
    ];

    protected $hidden = [
        'config_hash',
    ];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'last_deployed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (CloudDeployTarget $target): void {
            if ($target->isDirty('config') || ! $target->config_hash) {
                $target->config_hash = self::configHash((array) $target->config);
            }
        });
    }

    /** @param array<string,mixed> $config */
    public static function configHash(array $config): string
    {
        return hash('sha256', json_encode(
            self::normalizeConfig($config),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private static function normalizeConfig(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalizeConfig($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(CloudDeployAccess::class, 'access_id')->withoutGlobalScopes();
    }

    /**
     * 绑定的主系统订单。withoutGlobalScopes：admin 跨用户全量视图下不被主系统 Order 的 UserScope 收敛；
     * 域名搜索经 order.latestCert 链取 common_name，租户收敛锚点仍是 cloud_deploy_targets.user_id。
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Order::class, 'order_id')->withoutGlobalScopes();
    }
}
