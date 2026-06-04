<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Organization extends BaseModel
{
    use HasFactory, HasSnowflakeId;

    protected $fillable = [
        'user_id',
        'contact_id',
        'name',
        'registration_number',
        'country',
        'state',
        'city',
        'address',
        'postcode',
        'phone',
    ];

    protected $with = ['contact'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
