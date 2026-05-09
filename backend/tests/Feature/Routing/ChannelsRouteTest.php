<?php

use App\Providers\RouteServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Channel 路由开关行为
|--------------------------------------------------------------------------
|
| Laravel TestCase 已在 setUp 走完 RouteServiceProvider::boot()。
| 我们通过 reloadApiRoutes() 在改 config 后清空当前路由表 → 重跑
| RouteServiceProvider::registerApiRoutes()，模拟"重启进程读新 config"。
|
| 不依赖 RefreshDatabase（路由注册纯内存操作）。
*/

/**
 * 重新加载 /api/* 路由（按当前 config('channels.*') 状态）。
 */
function reloadApiRoutes(): void
{
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    RouteServiceProvider::registerApiRoutes(base_path('routes'));

    // 与 RouteServiceProvider boot 一致：刷新名称/动作 lookup
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

afterEach(function () {
    // 还原默认 channels 配置，避免污染后续测试
    config([
        'channels.admin' => true,
        'channels.user' => true,
        'channels.api' => true,
        'channels.deploy' => true,
    ]);
    reloadApiRoutes();
});

// ==========================================
// 1. 默认全启
// ==========================================

test('默认 channels 全启时 4 类路由全注册', function () {
    reloadApiRoutes();

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();

    // admin / user / api / deploy 各取代表性路由验证
    // 注：api.user.php 没有外层 prefix('user')，公开路由直接挂 /api/*（如 /api/login）
    expect($uris)->toContain('api/admin/login');
    expect($uris)->toContain('api/login');           // user channel
    expect($uris)->toContain('api/register');        // user channel
    expect($uris)->toContain('api/v2/get-products'); // api channel (v2)
    expect($uris)->toContain('api/deploy');          // deploy channel

    // health / meta 永远启用
    expect($uris)->toContain('api/health');
    expect($uris)->toContain('api/meta');
});

// ==========================================
// 2. 关闭 admin → admin 路由 404
// ==========================================

test('channels.admin=false 后 admin 路由不再注册（返回 404）', function () {
    config(['channels.admin' => false]);
    reloadApiRoutes();

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
    expect($uris)->not->toContain('api/admin/login');

    // user 等仍正常
    expect($uris)->toContain('api/login');

    $this->postJson('/api/admin/login', ['username' => 'x', 'password' => 'y'])
        ->assertStatus(404);
});

// ==========================================
// 3. 关闭 user → user 路由 404
// ==========================================

test('channels.user=false 后 user 路由不再注册', function () {
    config(['channels.user' => false]);
    reloadApiRoutes();

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
    // user channel 公开路由（无 prefix('user')，直接挂 /api/*）
    expect($uris)->not->toContain('api/login');
    expect($uris)->not->toContain('api/register');

    // admin / api / deploy 仍在
    expect($uris)->toContain('api/admin/login');
    expect($uris)->toContain('api/v2/get-products');

    $this->postJson('/api/login', ['username' => 'x', 'password' => 'y'])
        ->assertStatus(404);
});

// ==========================================
// 4. 关闭 api → v1/v2/acme 路由都 404
// ==========================================

test('channels.api=false 后 v1/v2/acme(api.acme.php) 路由都不再注册', function () {
    config(['channels.api' => false]);
    reloadApiRoutes();

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
    // v1 / v2 独有路径
    expect($uris)->not->toContain('api/V1/health');
    expect($uris)->not->toContain('api/v2/get-products');
    // api.acme.php 独有路径（user.php 没有 get-products / cancel；只有 user/admin acme 模块）
    expect($uris)->not->toContain('api/acme/get-products');
    expect($uris)->not->toContain('api/acme/cancel');

    // admin/user/deploy 仍在
    expect($uris)->toContain('api/admin/login');
    expect($uris)->toContain('api/login');
    expect($uris)->toContain('api/deploy');
});

// ==========================================
// 5. 关闭 deploy → deploy 路由 404
// ==========================================

test('channels.deploy=false 后 deploy 路由不再注册', function () {
    config(['channels.deploy' => false]);
    reloadApiRoutes();

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
    expect($uris)->not->toContain('api/deploy');
    expect($uris)->not->toContain('api/deploy/callback');
    expect($uris)->not->toContain('api/deploy/auto-reissue');

    // admin 端的 deploy-token 管理路由属于 admin channel，不受影响
    expect($uris)->toContain('api/admin/deploy-token');
});

// ==========================================
// 6. 全部关闭 → health + meta 仍可访问
// ==========================================

test('所有 channels 关闭时 /api/health 和 /api/meta 仍注册', function () {
    config([
        'channels.admin' => false,
        'channels.user' => false,
        'channels.api' => false,
        'channels.deploy' => false,
    ]);
    reloadApiRoutes();

    $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
    expect($uris)->toContain('api/health');
    expect($uris)->toContain('api/meta');

    // 业务路由全没了
    expect($uris)->not->toContain('api/admin/login');
    expect($uris)->not->toContain('api/login');
    expect($uris)->not->toContain('api/v2/get-products');
    expect($uris)->not->toContain('api/deploy');

    // /api/meta 仍可被路由解析（200 由 MetaController 决定）
    $this->getJson('/api/meta')->assertOk();
});
