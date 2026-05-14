<?php

use App\Utils\RouteHelper;
use Illuminate\Support\Facades\Route;
use Plugins\Invoice\Controllers\Admin\InvoiceController;

Route::prefix('api/admin')->middleware(['global', 'api.admin'])->group(function () {
    RouteHelper::registerResourceRoutes('invoice', InvoiceController::class);
    Route::get('invoice/quota/{userId}', [InvoiceController::class, 'quota'])->where('userId', '[0-9]+');
    Route::get('invoice/external-config', [InvoiceController::class, 'externalConfig']);
    Route::post('invoice/external-config', [InvoiceController::class, 'updateExternalConfig']);
    Route::post('invoice/external-config/regenerate-token', [InvoiceController::class, 'regenerateExternalToken']);
});
