<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * 升级 freeze 内部 smoke test 公共逻辑。
 *
 * 路由 POST /api/admin/upgrade/smoke 与 artisan 命令 upgrade:smoke 共用本类。
 *
 * 三项检查：
 *  - db：默认连接 PDO 可获取
 *  - jobs_table：jobs 表 SELECT 不抛异常（database driver 队列必备）
 *  - critical_routes：关键路由通过 Route::getRoutes() 已注册（不真正发请求避免循环）
 *
 * 任何 check 失败时整体 status=failed；否则 status=ok。
 */
class SmokeChecker
{
    /**
     * smoke test 必须存在的关键路由（无论 channels 配置都必须存在）。
     *
     * 名称是 Laravel Route::name(...) 命名；当前升级流程不依赖路由命名约定，
     * 改用 URI + method 二元组作为最低保证（unfreeze 后服务恢复用）。
     *
     * channels 开关相关的路由由 channelGatedRoutes() 动态决定（如 channels.api=false 时
     * 不应检查 api/v2/get-products，否则误判 smoke 失败 → 自动回滚正常升级）。
     *
     * @var list<array{method: string, uri: string}>
     */
    public const array CRITICAL_ROUTES = [
        ['method' => 'GET', 'uri' => 'api/health'],
        ['method' => 'POST', 'uri' => 'api/admin/upgrade/freeze'],
        ['method' => 'POST', 'uri' => 'api/admin/upgrade/unfreeze'],
        ['method' => 'POST', 'uri' => 'api/admin/upgrade/smoke'],
        ['method' => 'GET', 'uri' => 'api/admin/upgrade/status'],
    ];

    /**
     * 按 channel 开关动态决定是否参与 smoke 检查的路由。
     *
     * 例：channels.api=false 时 /api/v2/* 路由不注册；如果硬要求其存在会在 channel 关闭场景误报失败。
     *
     * @return list<array{method: string, uri: string}>
     */
    protected function channelGatedRoutes(): array
    {
        $routes = [];
        if ((bool) config('channels.api', true)) {
            // /api/v2/get-products 仅当 api channel 开启时存在
            $routes[] = ['method' => 'GET', 'uri' => 'api/v2/get-products'];
        }

        return $routes;
    }

    /**
     * 跑一遍全部检查，返回结构化结果。
     *
     * @return array{
     *     ok: bool,
     *     checks: array{
     *         db: array{ok: bool, message?: string},
     *         jobs_table: array{ok: bool, message?: string},
     *         critical_routes: array{ok: bool, missing?: list<string>}
     *     }
     * }
     */
    public function run(): array
    {
        $checks = [
            'db' => $this->dbCheck(),
            'jobs_table' => $this->jobsTableCheck(),
            'critical_routes' => $this->criticalRoutesCheck(),
        ];

        $allOk = true;
        foreach ($checks as $check) {
            if ($check['ok'] !== true) {
                $allOk = false;
                break;
            }
        }

        return [
            'ok' => $allOk,
            'checks' => $checks,
        ];
    }

    /**
     * DB 默认连接 PDO 可获取。
     *
     * @return array{ok: bool, message?: string}
     */
    public function dbCheck(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * jobs 表 SELECT count 不抛异常（database driver 队列必备表）。
     *
     * 当 queue.default 不是 database driver（如 redis / sync）时，jobs 表
     * 可能不存在，跳过此检查避免 smoke test 永远失败导致无法 unfreeze。
     *
     * @return array{ok: bool, message?: string}
     */
    public function jobsTableCheck(): array
    {
        if (config('queue.default') !== 'database') {
            return ['ok' => true, 'message' => 'queue driver is not database, skip jobs table check'];
        }

        try {
            DB::table('jobs')->count();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 关键路由都已注册（按 method + uri 匹配）。
     *
     * 不真正发请求，避免 freeze 期 smoke test 自调互锁。
     *
     * @return array{ok: bool, missing?: list<string>}
     */
    public function criticalRoutesCheck(): array
    {
        $registered = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            foreach ($route->methods() as $method) {
                $registered["{$method} $uri"] = true;
            }
        }

        $expectedRoutes = array_merge(self::CRITICAL_ROUTES, $this->channelGatedRoutes());
        $missing = [];
        foreach ($expectedRoutes as $expected) {
            $key = "{$expected['method']} {$expected['uri']}";
            if (! isset($registered[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            return ['ok' => false, 'missing' => $missing];
        }

        return ['ok' => true];
    }
}
