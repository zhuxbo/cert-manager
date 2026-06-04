<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Blade;
use Illuminate\Validation\ValidationException;

class NotificationTemplate extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'content',
        'variables',
        'example',
        'status',
    ];

    protected $casts = [
        'variables' => 'json',
        'status' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (NotificationTemplate $template) {
            $conflict = self::query()
                ->where('code', $template->code)
                ->when($template->exists, fn ($query) => $query->where('id', '!=', $template->getKey()))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'code' => ["$template->code 已存在，请勿重复配置"],
                ]);
            }
        });
    }

    /**
     * 通知
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'template_id');
    }

    /**
     * 渲染模板内容
     *
     * 使用 Blade 模板引擎渲染，支持完整的 Blade 语法
     */
    public function render(array $data = []): string
    {
        $content = (string) ($this->content ?? '');

        return Blade::render($content, $data);
    }

    protected function content(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? base64_decode($value) : null,
            set: fn ($value) => $this->encodeContent($value)
        );
    }

    protected function example(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? base64_decode($value) : null,
            set: fn ($value) => $this->encodeContent($value)
        );
    }

    protected function encodeContent(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = $value;
        if ($trimmed === '') {
            return null;
        }

        return base64_encode($trimmed);
    }
}
