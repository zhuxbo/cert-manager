<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiLog extends BaseModel
{
    const null UPDATED_AT = null;

    protected $fillable = [
        'correlation_id',
        'module',
        'action',
        'user_id',
        'version',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }
}
