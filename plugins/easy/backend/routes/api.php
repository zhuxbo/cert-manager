<?php

use Illuminate\Support\Facades\Route;
use Plugins\Easy\Controllers\EasyController;

Route::prefix('api/easy')->middleware(['global', 'easy.throttle'])->group(function () {
    Route::post('check', [EasyController::class, 'check']);
    Route::post('apply', [EasyController::class, 'apply']);
    Route::post('revalidate', [EasyController::class, 'revalidate']);
    Route::post('sync', [EasyController::class, 'sync']);
    Route::post('update-validation-method', [EasyController::class, 'updateValidationMethod']);
    Route::post('validate-file', [EasyController::class, 'validateFile']);
    Route::post('download', [EasyController::class, 'download']);
});
