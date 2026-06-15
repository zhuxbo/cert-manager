<?php

use App\Http\Controllers\Acme\ApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ACME API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v2/acme')->middleware('api.v2')->group(function () {
    Route::post('new', [ApiController::class, 'new']);
    Route::get('get', [ApiController::class, 'get']);
    Route::post('cancel', [ApiController::class, 'cancel']);
    Route::get('get-products', [ApiController::class, 'getProducts']);
});
