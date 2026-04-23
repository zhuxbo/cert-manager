<?php

namespace Plugins\Notice\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Plugins\Notice\Factories\NoticeFactory;

class Notice extends Model
{
    use HasFactory;

    protected $table = 'notices';

    protected $fillable = [
        'title',
        'content',
        'type',
        'position',
        'is_active',
        'sort',
    ];

    protected static function newFactory(): NoticeFactory
    {
        return NoticeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
