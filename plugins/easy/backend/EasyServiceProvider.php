<?php

namespace Plugins\Easy;

use Illuminate\Support\ServiceProvider;
use Plugins\Easy\Middleware\EasyRateLimiter;
use Plugins\Easy\Services\EasyLogPurger;

class EasyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([EasyLogHandler::class], 'plugin.log_handlers');
        $this->app->tag([EasyLogPurger::class], 'plugin.log_purgers');
    }

    public function boot(): void
    {
        $basePath = dirname(__DIR__);

        // 注册插件公开端点限流中间件别名（IP + tid 双维度），供 routes/api.php 使用
        $this->app['router']->aliasMiddleware('easy.throttle', EasyRateLimiter::class);

        $this->loadRoutesFrom("$basePath/backend/routes/api.php");
        $this->loadRoutesFrom("$basePath/backend/routes/callback.php");
        $this->loadRoutesFrom("$basePath/backend/routes/admin.php");
        $this->loadRoutesFrom("$basePath/backend/routes/user.php");
        $this->loadMigrationsFrom("$basePath/backend/migrations");

    }
}
