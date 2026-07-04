<?php

use Illuminate\Support\Facades\Route;
use Plugins\CloudDeploy\Controllers\Admin\CloudDeployController;

Route::prefix('api/admin')->middleware(['global', 'api.admin'])->group(function () {
    Route::prefix('cloud-deploy')->group(function () {
        Route::get('access', [CloudDeployController::class, 'accesses']);
        Route::get('target', [CloudDeployController::class, 'targets']);
        Route::post('target', [CloudDeployController::class, 'storeTarget']);
        Route::get('log', [CloudDeployController::class, 'logs']);
        Route::get('providers', [CloudDeployController::class, 'providers']);
        Route::post('deploy', [CloudDeployController::class, 'deploy']);
    });
});
