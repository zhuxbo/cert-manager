<?php

use Illuminate\Support\Facades\Route;
use Plugins\Easy\Controllers\EasyInvoiceController;

Route::prefix('api/easy/invoice')->middleware('global')->group(function () {
    Route::get('ping', [EasyInvoiceController::class, 'ping']);
    Route::post('quota', [EasyInvoiceController::class, 'quota']);
    Route::post('apply', [EasyInvoiceController::class, 'apply']);
});
