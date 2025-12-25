<?php

use App\Http\Controllers\Auto\ApiController;
use Illuminate\Support\Facades\Route;

// 中间件仅做 IP 限流
Route::prefix('auto')->middleware('api.auto')->group(function () {
    Route::get('cert', [ApiController::class, 'get']);
    Route::post('cert', [ApiController::class, 'update']);
    Route::post('callback', [ApiController::class, 'callback']);
});
