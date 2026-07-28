<?php

use Illuminate\Support\Facades\Route;
use Plugins\Notice\Controllers\Admin\NoticeController;

Route::prefix('api/admin')->middleware(['global', 'api.admin'])->group(function () {
    Route::prefix('notice')->group(function () {
        Route::get('/', [NoticeController::class, 'index']);
        Route::post('/', [NoticeController::class, 'store']);
        Route::put('{id}', [NoticeController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('{id}', [NoticeController::class, 'destroy'])->where('id', '[0-9]+');
        Route::patch('toggle/{id}', [NoticeController::class, 'toggle'])->where('id', '[0-9]+');
    });
});
