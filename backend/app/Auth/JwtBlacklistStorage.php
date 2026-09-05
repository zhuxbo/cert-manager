<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Foundation\Application;
use Tymon\JWTAuth\Providers\Storage\Illuminate;

final class JwtBlacklistStorage extends Illuminate
{
    public function __construct(Application $app)
    {
        parent::__construct($app->make('cache')->store('runtime'));
    }
}
