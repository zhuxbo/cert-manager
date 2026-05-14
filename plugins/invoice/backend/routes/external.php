<?php

use Illuminate\Support\Facades\Route;
use Plugins\Invoice\Controllers\External\InvoiceExternalController;

Route::prefix('api/invoice/external')
    ->middleware(['global', 'invoice.external'])
    ->group(function () {
        Route::get('pending', [InvoiceExternalController::class, 'pending']);
        Route::post('complete/{id}', [InvoiceExternalController::class, 'complete'])
            ->where('id', '[0-9]+');
    });
