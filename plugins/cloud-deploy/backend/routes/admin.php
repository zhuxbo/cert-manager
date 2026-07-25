<?php

use Illuminate\Support\Facades\Route;
use Plugins\CloudDeploy\Controllers\Admin\CloudDeployController;
use Plugins\CloudDeploy\Controllers\Admin\OrderOptionController;

Route::prefix('api/admin')->middleware(['global', 'api.admin'])->group(function () {
    Route::prefix('cloud-deploy')->group(function () {
        Route::get('access', [CloudDeployController::class, 'accesses']);
        Route::post('access', [CloudDeployController::class, 'storeAccess']);
        Route::get('access/{id}', [CloudDeployController::class, 'showAccess'])->where('id', '[0-9]+');
        Route::put('access/{id}', [CloudDeployController::class, 'updateAccess'])->where('id', '[0-9]+');
        Route::delete('access/{id}', [CloudDeployController::class, 'destroyAccess'])->where('id', '[0-9]+');
        Route::get('target', [CloudDeployController::class, 'targets']);
        Route::post('target', [CloudDeployController::class, 'storeTarget']);
        Route::get('target/{id}', [CloudDeployController::class, 'showTarget'])->where('id', '[0-9]+');
        Route::put('target/{id}', [CloudDeployController::class, 'updateTarget'])->where('id', '[0-9]+');
        Route::delete('target/{id}', [CloudDeployController::class, 'destroyTarget'])->where('id', '[0-9]+');
        Route::get('log', [CloudDeployController::class, 'logs']);
        Route::get('providers', [CloudDeployController::class, 'providers']);
        Route::get('order-options', [OrderOptionController::class, 'index']);
        Route::get('order-options/{id}', [OrderOptionController::class, 'show'])->where('id', '[0-9]+');
        Route::post('deploy', [CloudDeployController::class, 'deploy']);
    });
});
