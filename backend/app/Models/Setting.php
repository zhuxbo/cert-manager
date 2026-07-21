<?php

namespace App\Models;

use App\Bootstrap\ApiExceptions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

class Setting extends BaseModel
{
    use HasFactory;

    /**
     * 缓存前缀
     */
    private const string CACHE_PREFIX = 'setting:';

    /**
     * 缓存时间（秒），默认1小时
     */
    private const int CACHE_TTL = 3600;

    protected $fillable = [
        'group_id',
        'key',
        'type',
        'options',
        'is_multiple',
        'value',
        'description',
        'weight',
    ];

    protected $casts = [
        'options' => 'array',
        'is_multiple' => 'boolean',
    ];

    /**
     * 模型的"启动"方法
     */
    protected static function boot(): void
    {
        parent::boot();

        // 如果type不等于select 则is_multiple为0 options为空
        static::creating(function ($model) {
            if ($model->type !== 'select') {
                $model->is_multiple = 0;
                $model->options = null;
            }
        });

        static::created(function ($model) {
            $model->weight = $model->weight ?: $model->id;
            $model->save();
        });

        // 如果type不等于select 则is_multiple为0 options为空
        static::updating(function ($model) {
            if ($model->type !== 'select') {
                $model->is_multiple = 0;
                $model->options = null;
            }
        });

        // 数据变更时清除相关缓存
        static::saved(function ($model) {
            self::clearGroupCache($model->group_id);
        });

        static::deleted(function ($model) {
            self::clearGroupCache($model->group_id);
        });
    }

    /**
     * 在获取value属性时自动转换类型
     */
    public function getValueAttribute($value)
    {
        if (! empty($this->attributes['type']) && isset($this->attributes['value']) && $this->attributes['value'] === $value) {
            return $this->getTypedValue();
        }

        return $value;
    }

    /**
     * 在设置value属性时自动格式化
     */
    public function setValueAttribute($value): void
    {
        if (isset($this->attributes['type'])) {
            $this->attributes['value'] = $this->formatValue($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    /**
     * 按组获取设置
     */
    public static function getByGroupId(int $groupId): array
    {
        $cacheKey = self::CACHE_PREFIX.'group:'.$groupId;

        $loader = function () use ($groupId) {
            /** @var Setting[] $settings */
            $settings = self::where('group_id', $groupId)->orderBy('weight')->get();
            $result = [];

            foreach ($settings as $setting) {
                $result[$setting->key] = $setting->getTypedValue();
            }

            return $result;
        };

        try {
            return Cache::remember($cacheKey, self::CACHE_TTL, $loader);
        } catch (Throwable) {
            // Cache 后端故障（如 redis 宕机）→ 直读 DB。settings 是告警配置与 health 阈值的依赖，
            // 绝不能因 cache 死而整链哑火。
            return $loader();
        }
    }

    /**
     * 按组获取设置（使用组名）
     */
    public static function getByGroupName(string $groupName): array
    {
        $cacheKey = self::CACHE_PREFIX.'group_name:'.$groupName;

        $loader = function () use ($groupName) {
            $group = SettingGroup::where('name', $groupName)->first();
            if (! $group) {
                return [];
            }

            return self::getByGroupId($group->id);
        };

        try {
            return Cache::remember($cacheKey, self::CACHE_TTL, $loader);
        } catch (Throwable) {
            // Cache 后端故障回落 DB 直读（同 getByGroupId 注释）。
            return $loader();
        }
    }

    /**
     * 按组设置多个值
     */
    public static function setByGroupId(int $groupId, array $values): bool
    {
        foreach ($values as $key => $value) {
            $setting = self::where('group_id', $groupId)->where('key', $key)->first();
            if ($setting) {
                $setting->value = $value;
                $setting->save();
            }
        }

        return true;
    }

    /**
     * 获取设置值
     */
    public static function getValue(string $groupName, ?string $key = null): mixed
    {
        if ($key === null) {
            return self::getByGroupName($groupName);
        }

        $values = self::getByGroupName($groupName);

        return $values[$key] ?? null;
    }

    /**
     * 设置值
     */
    public static function setValue(string $groupName, int|string|array $keyOrValues, mixed $value = null): bool
    {
        $group = SettingGroup::where('name', $groupName)->first();
        if (! $group) {
            return false;
        }

        if (is_array($keyOrValues)) {
            return self::setByGroupId($group->id, $keyOrValues);
        }

        $setting = self::where('group_id', $group->id)
            ->where('key', $keyOrValues)
            ->first();

        if ($setting) {
            $setting->value = $value;

            return $setting->save();
        }

        return false;
    }

    /**
     * 获取正确类型的值
     */
    public function getTypedValue(): mixed
    {
        if (! isset($this->attributes['type']) || ! isset($this->attributes['value'])) {
            return null;
        }

        $value = $this->attributes['value'];
        $type = $this->attributes['type'];
        $isMultiple = $this->attributes['is_multiple'] ?? false;

        try {
            return match ($type) {
                'integer' => (int) $value,
                'float' => (float) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'array' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
                'select' => $isMultiple ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
                'base64' => base64_decode($value),
                default => $value,
            };
        } catch (JsonException $e) {
            app(ApiExceptions::class)->logException($e);

            return $isMultiple ? [] : null;
        }
    }

    /**
     * 格式化要保存的值
     */
    protected function formatValue(mixed $value): string
    {
        if (! isset($this->attributes['type'])) {
            return (string) $value;
        }
        $type = $this->attributes['type'];
        $isMultiple = $this->attributes['is_multiple'] ?? false;

        try {
            return match ($type) {
                'array' => json_encode($value ?? [], JSON_THROW_ON_ERROR),
                'select' => $isMultiple ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value,
                'boolean' => $value ? '1' : '0',
                'base64' => base64_encode((string) $value),
                default => (string) $value,
            };
        } catch (JsonException $e) {
            app(ApiExceptions::class)->logException($e);

            return '[]';
        }
    }

    /**
     * 设置项所属的设置组
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SettingGroup::class, 'group_id');
    }

    /**
     * 清除指定组的缓存
     */
    public static function clearGroupCache(int $groupId): void
    {
        Cache::forget(self::CACHE_PREFIX.'group:'.$groupId);

        $group = SettingGroup::find($groupId);
        if ($group) {
            Cache::forget(self::CACHE_PREFIX.'group_name:'.$group->name);

            // 支付配置（wechat/alipay）另有独立缓存 pay_config_{group}（PaymentConfigTrait，365 天，
            // 含已注册的微信公钥 / 支付宝证书路径）。保存支付设置时必须同步清掉，否则缓存与 live 设置
            // 不一致：wechat 公钥轮换后 getPayConfig 命中旧缓存只注册旧公钥，而 wechatSerial 实时读
            // live 发新 serial 头，微信遂以新公钥签回调、本地却验不了 → 回调验签失败、入账中断。
            if (in_array($group->name, ['wechat', 'alipay'], true)) {
                Cache::forget('pay_config_'.$group->name);
            }
        }
    }

    /**
     * 清除所有 Setting 缓存
     */
    public static function clearAllCache(): void
    {
        $groups = SettingGroup::all();
        foreach ($groups as $group) {
            self::clearGroupCache($group->id);
        }
    }
}
