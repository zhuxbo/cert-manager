<?php

namespace App\Http\Controllers;

use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    /**
     * 对外 API 接口文档（原文 Markdown，供 curl / 非 SPA 接入方读取）
     *
     * 路由 GET /api/meta/api-doc?surface=v2|acme|deploy
     * 源文件随版本发布打包（backend/resources/docs/api/*.md），与 SPA 内构建期编译同源。
     * 公开、无鉴权（文档描述的是公开契约，本身不含敏感信息）。
     */
    public function apiDoc(Request $request): Response
    {
        $surface = (string) $request->query('surface', '');

        if (! in_array($surface, ['v2', 'acme', 'deploy'], true)) {
            abort(404);
        }

        $path = resource_path("docs/api/$surface.md");
        if (! is_file($path)) {
            abort(404);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
