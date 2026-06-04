<?php

use Illuminate\Support\Facades\Route;
use Plugins\Easy\Controllers\EasyInvoiceController;

// 与同插件 routes/api.php 一致挂 easy.throttle：quota/apply 是免登录、仅凭 tid+email 自证的端点，
// 必须共享同一限流桶（tid + IP 双维度）防枚举/滥用，且跨 action 共享计数避免换接口绕过。
Route::prefix('api/easy/invoice')->middleware(['global', 'easy.throttle'])->group(function () {
    Route::get('ping', [EasyInvoiceController::class, 'ping']);
    Route::post('quota', [EasyInvoiceController::class, 'quota']);
    Route::post('apply', [EasyInvoiceController::class, 'apply']);
});
