<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminLog extends BaseModel
{
    const null UPDATED_AT = null;

    protected $fillable = [
        'correlation_id',
        'admin_id',
        'module',
        'action',
        'method',
        'url',
        'params',
        'response',
        'status_code',
        'status',
        'duration',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'params' => 'json',
        'response' => 'json',
        'status_code' => 'integer',
        'status' => 'integer',
        'duration' => 'decimal:2',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class)->withoutGlobalScopes();
    }
}
