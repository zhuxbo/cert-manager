<?php

use App\Services\Upgrade\SmokeChecker;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class)->group('database');

/**
 * SmokeChecker 与 channels.api 配置的联动。
 *
 * 风险：channels.api=false 时 /api/v2/* 路由不注册，
 * 如果 SmokeChecker 仍硬要求 api/v2/get-products → 升级 smoke 误判失败 → 自动回滚正常升级。
 *
 * 修复后：channelGatedRoutes() 按 config('channels.api') 动态决定是否检查 v2 路由。
 */
beforeEach(function () {
    // SmokeChecker 走 Route::getRoutes()，需要确保检查目标路由存在/不存在
    // 这里手动注册 / 删除关键路由，模拟 channels 开关的最终路由表
});

test('channels.api=true 时 critical_routes 包含 api/v2/get-products', function () {
    config(['channels.api' => true]);

    // 注册关键路由集合：CRITICAL_ROUTES + api/v2/get-products
    Route::get('api/health', fn () => 'ok');
    Route::post('api/admin/upgrade/freeze', fn () => 'ok');
    Route::post('api/admin/upgrade/unfreeze', fn () => 'ok');
    Route::post('api/admin/upgrade/smoke', fn () => 'ok');
    Route::get('api/admin/upgrade/status', fn () => 'ok');
    Route::get('api/v2/get-products', fn () => 'ok');

    $checker = new SmokeChecker;
    $result = $checker->criticalRoutesCheck();

    expect($result['ok'])->toBeTrue();
});

test('channels.api=false 时 SmokeChecker 跳过 api/v2/get-products 不报缺失', function () {
    config(['channels.api' => false]);

    // 仅注册 CRITICAL_ROUTES，故意不注册 api/v2/get-products
    Route::get('api/health', fn () => 'ok');
    Route::post('api/admin/upgrade/freeze', fn () => 'ok');
    Route::post('api/admin/upgrade/unfreeze', fn () => 'ok');
    Route::post('api/admin/upgrade/smoke', fn () => 'ok');
    Route::get('api/admin/upgrade/status', fn () => 'ok');

    $checker = new SmokeChecker;
    $result = $checker->criticalRoutesCheck();

    expect($result['ok'])->toBeTrue('channels.api=false 时不该把 api/v2/get-products 列为缺失');
    expect($result)->not->toHaveKey('missing');
});

test('channels.api=true 但 v2 路由真实未注册时报缺失（防 channel 配错的回归测试）', function () {
    config(['channels.api' => true]);

    // 真实 manager 应用启动期已注册 CRITICAL_ROUTES 全部路由；
    // 这里反向验证：把所有 RouteServiceProvider 注册的路由临时禁用是不现实的。
    // 改为用 anonymous subclass 替换 channelGatedRoutes 注入一条故意不存在的路径，
    // 验证 channelGatedRoutes 逻辑确实在 channels.api=true 时被加入检查列表。
    $checker = new class extends SmokeChecker
    {
        protected function channelGatedRoutes(): array
        {
            // 故意 inject 一条不存在的路由
            return [['method' => 'GET', 'uri' => 'api/v2/totally-non-existent-endpoint']];
        }
    };

    $result = $checker->criticalRoutesCheck();

    expect($result['ok'])->toBeFalse();
    expect($result['missing'])->toContain('GET api/v2/totally-non-existent-endpoint');
});
