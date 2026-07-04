<?php

namespace App\Http\Middleware;

use App\Utils\UpgradeFreezeLock;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 升级维护态中间件
 *
 * freeze 期间所有非白名单请求返回 503 + Retry-After: 60；
 * 白名单请求正常通过。信号源是 UpgradeFreezeLock 文件锁
 * (storage/framework/upgrade.lock)，与 cache driver 完全解耦。
 */
class MaintenanceMode
{
    /**
     * freeze 期间允许通过的路径（精确匹配）
     *
     * 每条路径必须有明确理由：要么用于探活/升级管理本身，要么用于
     * 让 admin 在升级期间维持登录状态以便观察进度。修改性路由（如
     * update-profile / update-password）一律不放行——freeze 期禁写。
     *
     * @var list<string|array{0: string, 1: string}>
     */
    protected array $whitelist = [
        // 健康检查（1a-4 新建 + 现有 v1/v2）—— 宝塔 healthcheck + 升级流程内部探活
        'api/health',
        'api/v1/health',
        'api/v2/health',

        // 公开元信息端点（与 health 同级公共可读，freeze 期前端仍能消费）
        'api/meta',

        // 升级管理本身——freeze/unfreeze/status/smoke/opcache-reset 等都必须在 freeze 期可调
        'api/admin/upgrade/*',

        // admin 会话保活——freeze 期 admin 仍能登入观察升级进度
        'api/admin/login',          // 登录入口
        'api/admin/refresh-token',  // 刷新 access token
        'api/admin/me',             // 获取当前 admin 信息（前端守卫探活）
        'api/admin/logout',         // 登出

        // 插件安装/更新异步任务状态只读查询；freeze 期允许观察状态，但不允许写操作
        ['GET', 'api/admin/plugin/operations'],
        ['GET', 'api/admin/plugin/operations/*'],
    ];

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! UpgradeFreezeLock::isFrozen()) {
            return $next($request);
        }

        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        return new JsonResponse([
            'status' => 'frozen',
            'message' => '系统升级中，请稍后重试',
            'retry_after' => 60,
        ], Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => '60',
        ]);
    }

    /**
     * 判断当前请求路径是否命中白名单
     *
     * 复用 Laravel Request::is() 的 glob 匹配（与 LogOperation 一致），
     * 通配符仅在 upgrade/* 上使用，其余条目均为精确路径。
     *
     * 注意：public 可见性用于供 LogOperation 等其他中间件复用，避免
     * 维护两份白名单导致漂移。
     */
    public function isWhitelisted(Request $request): bool
    {
        foreach ($this->whitelist as $pattern) {
            if (is_array($pattern)) {
                [$method, $path] = $pattern;
                if ($request->isMethod($method) && $request->is($path)) {
                    return true;
                }

                continue;
            }

            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
