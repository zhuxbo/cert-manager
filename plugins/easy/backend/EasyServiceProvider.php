<?php

namespace Plugins\Easy;

use Illuminate\Support\ServiceProvider;
use Plugins\Invoice\Models\Invoice;

class EasyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([EasyLogHandler::class], 'plugin.log_handlers');
    }

    public function boot(): void
    {
        $basePath = dirname(__DIR__);

        $this->loadRoutesFrom("$basePath/backend/routes/api.php");
        $this->loadRoutesFrom("$basePath/backend/routes/callback.php");
        $this->loadRoutesFrom("$basePath/backend/routes/admin.php");
        $this->loadRoutesFrom("$basePath/backend/routes/user.php");
        $this->loadMigrationsFrom("$basePath/backend/migrations");

        if (class_exists(Invoice::class)) {
            $this->loadRoutesFrom("$basePath/backend/routes/invoice.php");
        }
    }
}
