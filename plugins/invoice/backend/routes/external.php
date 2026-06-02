<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Plugins\Invoice\Controllers\External\InvoiceExternalController;

// 在 token 鉴权之前按 IP 做一道宽松限流（300/min）：token 为 32 字符随机 + hash_equals，
// 限流主要作为纵深防御兜住对鉴权中间件的爆破/滥用。300/min 对机器对机器轮询足够宽松，
// 如对接方轮询更激进可上调或移除。
Route::prefix('api/invoice/external')
    ->middleware(['global', ThrottleRequests::class.':300,1', 'invoice.external'])
    ->group(function () {
        Route::get('pending', [InvoiceExternalController::class, 'pending']);
        Route::post('complete/{id}', [InvoiceExternalController::class, 'complete'])
            ->where('id', '[0-9]+');
    });
