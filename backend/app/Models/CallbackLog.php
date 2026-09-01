<?php

namespace App\Models;

class CallbackLog extends BaseModel
{
    const null UPDATED_AT = null;

    protected $fillable = [
        'correlation_id',
        'module',
        'action',
        'method',
        'url',
        'params',
        'response',
        'ip',
        'status',
    ];

    protected $casts = [
        'params' => 'json',
        'response' => 'json',
        'status' => 'integer',
    ];
}
