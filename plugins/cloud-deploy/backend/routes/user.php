<?php

use Illuminate\Support\Facades\Route;
use Plugins\CloudDeploy\Controllers\User\AccessController;
use Plugins\CloudDeploy\Controllers\User\DeployController;
use Plugins\CloudDeploy\Controllers\User\LogController;
use Plugins\CloudDeploy\Controllers\User\OrderOptionController;
use Plugins\CloudDeploy\Controllers\User\ProviderController;
use Plugins\CloudDeploy\Controllers\User\TargetController;

Route::prefix('api')->middleware(['global', 'api.user'])->group(function () {
    Route::prefix('cloud-deploy')->group(function () {
        Route::get('access', [AccessController::class, 'index']);
        Route::post('access', [AccessController::class, 'store']);
        Route::get('access/{id}', [AccessController::class, 'show'])->where('id', '[0-9]+');
        Route::put('access/{id}', [AccessController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('access/{id}', [AccessController::class, 'destroy'])->where('id', '[0-9]+');

        Route::get('target', [TargetController::class, 'index']);
        Route::post('target', [TargetController::class, 'store']);
        Route::get('target/{id}', [TargetController::class, 'show'])->where('id', '[0-9]+');
        Route::put('target/{id}', [TargetController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('target/{id}', [TargetController::class, 'destroy'])->where('id', '[0-9]+');

        Route::get('log', [LogController::class, 'index']);

        Route::get('providers', [ProviderController::class, 'index']);

        Route::get('order-options', [OrderOptionController::class, 'index']);
        Route::get('order-options/{order}', [OrderOptionController::class, 'show'])->where('order', '[0-9]+');

        Route::post('deploy', [DeployController::class, 'deploy']);
    });
});
