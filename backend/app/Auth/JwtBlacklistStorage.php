<?php

declare(strict_types=1);

namespace App\Auth;

use App\Services\Upgrade\LegacyJwtBlacklistMigration;
use App\Services\Upgrade\RuntimeSessionCutover;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Providers\Storage\Illuminate;

final class JwtBlacklistStorage extends Illuminate
{
    private ?Illuminate $legacyStorage;

    public function __construct(Application $app)
    {
        parent::__construct($app->make('cache')->store('runtime'));
        $this->legacyStorage = RuntimeSessionCutover::isPending()
            ? new Illuminate($app->make('cache')->store())
            : null;
    }

    public function get($key)
    {
        // 搬迁期间双读旧库；文件缓存的历史 jti 只能按原 SHA1 查找。
        return parent::get($key)
            ?? Cache::store('runtime')->get(LegacyJwtBlacklistMigration::FILE_KEY_PREFIX.sha1($key))
            ?? $this->legacyStorage?->get($key);
    }
}
