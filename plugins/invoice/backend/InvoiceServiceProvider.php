<?php

namespace Plugins\Invoice;

use Illuminate\Support\ServiceProvider;
use Plugins\Invoice\Http\Middleware\InvoiceExternalAuth;

class InvoiceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $basePath = dirname(__DIR__);

        $router = $this->app['router'];
        $router->aliasMiddleware('invoice.external', InvoiceExternalAuth::class);

        $this->loadRoutesFrom("$basePath/backend/routes/admin.php");
        $this->loadRoutesFrom("$basePath/backend/routes/user.php");
        $this->loadRoutesFrom("$basePath/backend/routes/external.php");
        $this->loadMigrationsFrom("$basePath/backend/migrations");
    }
}
