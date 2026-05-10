<?php

namespace App\Http\Controllers;

use App\Services\Plugin\PluginManager;

/**
 * 公开元信息端点
 *
 * 路由 GET /api/meta，前端启动期消费：
 *   - 决定是否显示 admin/user 入口（按 channels 开关）
 *   - 决定是否加载插件 bundle（按 plugins 列表）
 *
 * 不需要 token / cookie / session（类似 /api/health）；
 * 不写业务日志（LogOperation 排除）；
 * 不受 MaintenanceMode freeze 拦截（白名单）。
 *
 * 响应：
 * {
 *   "code": 1,
 *   "data": {
 *     "channels": { "admin": bool, "user": bool, "api": bool, "deploy": bool },
 *     "plugins":  [ { "name": string, "version": string|null }, ... ],
 *     "version":  string  // config('version.version')
 *   }
 * }
 */
class MetaController extends Controller
{
    public function index(PluginManager $plugins): never
    {
        $installed = $plugins->getInstalledPlugins();

        $this->success([
            'channels' => [
                'admin' => (bool) config('channels.admin', true),
                'user' => (bool) config('channels.user', true),
                'api' => (bool) config('channels.api', true),
                'deploy' => (bool) config('channels.deploy', true),
            ],
            'plugins' => array_values(array_map(
                static fn (array $p): array => [
                    'name' => (string) ($p['name'] ?? ''),
                    'version' => isset($p['version']) ? (string) $p['version'] : null,
                ],
                $installed,
            )),
            'version' => (string) config('version.version', 'unknown'),
        ]);
    }
}
