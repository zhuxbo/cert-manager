<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserLevel extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'custom',
        'cost_rate',
        'weight',
    ];

    protected $casts = [
        'custom' => 'integer',
        'cost_rate' => 'decimal:4',
        'weight' => 'integer',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'level_code', 'code');
    }

    public function customUsers(): HasMany
    {
        return $this->hasMany(User::class, 'custom_level_code', 'code');
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'level_code', 'code');
    }
}
