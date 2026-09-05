<?php

declare(strict_types=1);

namespace App\Auth;

use App\Services\Upgrade\RuntimeSessionCutover;
use Illuminate\Contracts\Foundation\Application;
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
        // 延后吊销期间仍须识别旧库里的已登出 token；新吊销始终写 runtime。
        return parent::get($key) ?? $this->legacyStorage?->get($key);
    }
}
