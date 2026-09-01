<?php

namespace App\Models;

class ErrorLog extends BaseModel
{
    const null UPDATED_AT = null;

    protected $fillable = [
        'correlation_id',
        'module',
        'action',
        'method',
        'url',
        'exception',
        'message',
        'trace',
        'status_code',
        'ip',
    ];

    protected $casts = [
        'trace' => 'json',
        'status_code' => 'integer',
    ];
}
