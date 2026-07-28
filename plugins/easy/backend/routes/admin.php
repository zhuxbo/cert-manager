<?php

use Illuminate\Support\Facades\Route;
use Plugins\Easy\Controllers\Admin\AgisoController;
use Plugins\Easy\Controllers\Admin\LogsController;

Route::prefix('api/admin')->middleware(['global', 'api.admin'])->group(function () {
    Route::prefix('agiso')->group(function () {
        Route::get('products', [AgisoController::class, 'products']);
        Route::post('/', [AgisoController::class, 'store']);
        Route::get('/', [AgisoController::class, 'index']);
        Route::get('{id}', [AgisoController::class, 'show'])->where('id', '[0-9]+');
        Route::delete('{id}', [AgisoController::class, 'destroy'])->where('id', '[0-9]+');
        Route::delete('batch', [AgisoController::class, 'batchDestroy']);
    });

    Route::prefix('logs')->group(function () {
        Route::get('easy', [LogsController::class, 'easy']);
        Route::get('easy/{id}', [LogsController::class, 'get'])->where('id', '[0-9]+');
    });
});
