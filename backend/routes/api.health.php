<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Health Route
|--------------------------------------------------------------------------
|
| 公开运维健康检查入口，命名空间无关：GET /api/health。
| 不挂任何鉴权 / token / session 中间件；不写日志；不受 MaintenanceMode 拦截。
|
| 由 RouteServiceProvider 自动 glob 加载到 Route::prefix('api') 下，
| 中间件仅含 SubstituteBindings（global 组）。
|
| 与现有 /api/v1/health、/api/v2/health（API 业务接口）共存。
*/
Route::get('health', [HealthController::class, 'index']);
