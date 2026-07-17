<?php

namespace Plugins\CloudDeploy\Models;

use App\Models\BaseModel;
use App\Models\Traits\HasSnowflakeId;

class CloudDeployLog extends BaseModel
{
    use HasSnowflakeId;

    protected $table = 'cloud_deploy_logs';

    public const null UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'target_id', 'order_id', 'cert_id',
        'provider', 'product', 'resource_summary', 'access_name',
        'trigger', 'status', 'attempt_no', 'is_final', 'remote_cert_id', 'error_code', 'message', 'deployed_at',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'is_final' => 'boolean',
        'deployed_at' => 'datetime',
    ];
}
