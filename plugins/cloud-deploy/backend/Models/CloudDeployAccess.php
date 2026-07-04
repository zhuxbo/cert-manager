<?php

namespace Plugins\CloudDeploy\Models;

use App\Models\BaseModel;
use App\Models\Traits\HasSnowflakeId;
use Illuminate\Support\Carbon;

/**
 * 云部署凭证（AK/SK）。属性对应 cloud_deploy_accesses 表列，供静态分析解析 Eloquent 魔术属性。
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $provider
 * @property array $credentials encrypted:array cast（明文 AK/SK）
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CloudDeployAccess extends BaseModel
{
    use HasSnowflakeId;

    protected $table = 'cloud_deploy_accesses';

    protected $fillable = ['user_id', 'name', 'provider', 'credentials'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
    ];
}
