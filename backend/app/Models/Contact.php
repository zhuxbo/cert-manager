<?php

namespace App\Models;

use App\Models\Traits\HasSnowflakeId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends BaseModel
{
    use HasFactory, HasSnowflakeId;

    protected $appends = ['full_name'];

    protected $attributes = [
        'last_name' => '',
        'first_name' => '',
    ];

    protected $fillable = [
        'user_id',
        'last_name',
        'first_name',
        'identification_number',
        'title',
        'email',
        'phone',
    ];

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => "$this->last_name $this->first_name",
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
