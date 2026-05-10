<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * 按 channel 开关注册路由文件：
     *   - config/channels.php / env CHANNELS_* 控制 admin/user/api/deploy
     *   - 关闭时跳过对应 routes/api.*.php，路由根本不注册（非中间件拦截）
     *
     * 永远启用（与 channel 解耦）：
     *   - api.health.php  公开运维健康检查
     *   - api.meta.php    前端启动期 channel/plugin 元信息
     *
     * 切换需重启 PHP-FPM；不支持热更。
     */
    public function boot(): void
    {
        $this->routes(function () {
            self::registerApiRoutes(base_path('routes'));
        });

        // callback 通配路由最后注册，确保插件的具体回调路由优先匹配
        $this->app->booted(function () {
            Route::middleware('global')->group(base_path('routes/callback.php'));
        });
    }

    /**
     * 按 channels 配置注册 /api/* 路由文件。
     *
     * 提取为 static 是为了让测试可以在改 config 后清空路由集合 → 重跑这段逻辑，
     * 不需要重新引导整个应用。boot() 与测试 helper 共用同一段实现，避免漂移。
     *
     * 注册顺序保持与历史 glob('api.*.php') 字母序一致（acme < admin < deploy < health
     * < meta < user < v1 < v2），以兼容路径冲突时"后注册覆盖前注册"的现有行为。
     * 例如 /api/acme/new 同时在 api.acme.php（api channel）和 api.user.php
     * （user channel）声明，历史上 user.php 在 acme.php 之后注册 → User\AcmeController
     * 覆盖 Acme\ApiController；改为按 channel 分组注册时必须保留同样顺序。
     */
    public static function registerApiRoutes(string $routePath): void
    {
        // channel → routes/api.*.php 文件名映射 + 注册顺序权重（小者先注册）。
        // 权重对应字母序，确保 /api/acme/new 等冲突路径仍由 user.php 覆盖 api.acme.php。
        $files = [
            // file => [channel, weight]
            'api.acme.php' => ['api', 10],
            'api.admin.php' => ['admin', 20],
            'api.deploy.php' => ['deploy', 30],
            // 永远启用：health / meta（与 channel 解耦，channel 全关也注册）
            'api.health.php' => [null, 40],
            'api.meta.php' => [null, 50],
            'api.user.php' => ['user', 60],
            'api.v1.php' => ['api', 70],
            'api.v2.php' => ['api', 80],
        ];

        // 按权重排序后逐个注册
        uasort($files, static fn ($a, $b) => $a[1] <=> $b[1]);

        Route::prefix('api')->group(function () use ($routePath, $files) {
            foreach ($files as $file => [$channel, $_weight]) {
                if ($channel !== null && ! config("channels.$channel", true)) {
                    continue;
                }
                $path = "$routePath/$file";
                if (file_exists($path)) {
                    Route::middleware('global')->group($path);
                }
            }
        });
    }
}
