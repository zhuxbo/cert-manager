<?php

use App\Http\Controllers\MetaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Meta Route
|--------------------------------------------------------------------------
|
| 前端启动期消费的元信息端点：GET /api/meta。
| 返回 channels（已开启列表）+ plugins（已装清单）+ version + platform。
|
| 不挂任何鉴权 / token / session 中间件；不写日志；不受 MaintenanceMode 拦截。
| 由 RouteServiceProvider 永久注册（与 channel 解耦），中间件仅 SubstituteBindings。
*/

Route::get('meta', [MetaController::class, 'index'])
    ->middleware('cache.headers:no_store;private');
Route::get('meta/site-image/{filename}', [MetaController::class, 'siteImage'])
    ->where('filename', '(?:logo-[a-f0-9]{64}\.(?:jpg|png|webp|svg)|qrcode-[a-f0-9]{64}\.(?:jpg|png|webp))');

// 对外 API 接口文档（OpenAPI 3.1 YAML），供 curl / Scalar 渲染读取；公开无鉴权
Route::get('meta/api-doc', [MetaController::class, 'apiDoc']);
