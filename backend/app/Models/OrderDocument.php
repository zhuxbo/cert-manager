<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDocument extends BaseModel
{
    protected $fillable = [
        'order_id',
        'user_id',
        'type',
        'file_name',
        'file_path',
        'file_size',
        'content_hash',
        'uploaded_by',
        'submitted',
        'submitted_at',
        'submit_attempts',
        'submit_error',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'submitted' => 'boolean',
        'submitted_at' => 'datetime',
        'submit_attempts' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
