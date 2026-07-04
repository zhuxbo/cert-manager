<?php

namespace Plugins\CloudDeploy\Models;

use App\Models\BaseModel;
use App\Models\Traits\HasSnowflakeId;

class CloudDeployRemoteCert extends BaseModel
{
    use HasSnowflakeId;

    protected $table = 'cloud_deploy_remote_certs';

    public const null UPDATED_AT = null;

    protected $fillable = ['user_id', 'access_id', 'cert_id', 'fingerprint', 'store_kind', 'remote_cert_id'];
}
