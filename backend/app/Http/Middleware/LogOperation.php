<?php

namespace App\Http\Middleware;

use App\Contracts\PluginLogHandler;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Models\CallbackLog;
use App\Models\UserLog;
use App\Services\LogBuffer;
use App\Utils\LogScrubber;
use App\Utils\UpgradeFreezeLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class LogOperation
{
    /**
     * 不需要记录日志的路由
     */
    protected array $excludedPaths = [
        '*/index*',
        '*/list/*',
        '*/get/*',
        'api/admin/logs/*',
        // 公开运维健康检查（精确匹配，避免误伤未来 /api/health/... 子路径）
        'api/health',
        'api/V1/*/health',
        'api/v2/*/health',
        // 公开元信息端点（前端启动期高频访问，不写日志）
        'api/meta',
        'acme/*',
        '.well-known/*',
        '*document-preview*',
        '_debugger/*',
        '_ignition/*',
    ];

    /**
     * 不需要记录响应内容的路由
     */
    protected array $excludeResponsePaths = [
        '*download*',
        '*export*',
    ];

    /**
     * 处理请求
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next)
    {
        // 检查是否需要记录日志
        if ($this->shouldSkipLogging($request)) {
            return $next($request);
        }

        // 在请求生命周期开始就生成 correlation_id，并 prepend 到 request attributes / 容器单例。
        // 下游 LogBuffer / Sdk / ApiExceptions / TaskJob 都通过 app('correlation_id') 读取注入。
        // 优先采用客户端传入的 X-Correlation-Id header（多级代理场景下保持链路连续），
        // 否则 Str::uuid() 自动生成；header 必须满足 [a-zA-Z0-9_-]{1,40} 防止恶意注入。
        $incomingId = (string) $request->header('X-Correlation-Id', '');
        $correlationId = ($incomingId !== '' && preg_match('/^[a-zA-Z0-9_\-]{1,40}$/', $incomingId))
            ? $incomingId
            : (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);
        app()->instance('correlation_id', $correlationId);

        // 记录开始时间
        $startTime = microtime(true);

        try {
            // 继续处理请求
            $response = $next($request);

            // 计算耗时
            $duration = microtime(true) - $startTime;

            // 获取响应内容
            if (! $this->shouldSkipResponse($request)) {
                $responseContent = $response->getContent();
                $sanitizedResponse = LogScrubber::scrubResponse($responseContent);
                // 用于微信支付回调
                $content = json_decode($responseContent, true);
            }

            $status = 0;
            // 用于支付宝回调 放前面 先检查
            if (isset($responseContent)) {
                $status = intval(strtolower($responseContent) === 'success');
            }
            // 用于微信支付回调
            if (isset($content['code'])) {
                if (is_string($content['code'])) {
                    $status = intval(strtolower($content['code']) === 'success');
                }
                if (is_int($content['code'])) {
                    $status = intval(boolval($content['code']));
                }
            }

            // 基础日志数据
            $logData = [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'params' => LogScrubber::scrub($request->all()),
                'response' => $sanitizedResponse ?? null,
                'status_code' => $response->getStatusCode(),
                'status' => $status,
                'duration' => round($duration, 3),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];

            // 根据路由前缀记录不同类型的日志
            if ($request->is(['api/V1/*', 'api/v2/*', 'api/acme/*', 'api/deploy', 'api/deploy/*'])) {
                $this->logApiRequest($request, $logData);
            } elseif ($request->is('api/admin/*')) {
                $this->logAdminRequest($request, $logData);
            } elseif ($this->handlePluginLog($request, $logData)) {
                // 插件日志处理器已处理
            } elseif ($request->is('callback/*')) {
                $this->logCallbackRequest($logData);
            } else {
                $this->logUserRequest($request, $logData);
            }
        } catch (Throwable $e) {
            report($e);
            throw $e;
        }

        return $response;
    }

    /**
     * 判断是否需要跳过记录日志
     *
     * freeze 期间：
     *  - 白名单外路径（被 MaintenanceMode 直接 503 的）必然短路；
     *  - 白名单内路径（health / upgrade / admin 会话保活等）正常写日志，
     *    保留升级流程审计追溯。升级流程默认不备份/还原数据库
     *    （config/upgrade.php backup.include.database = false），
     *    无回滚污染顾虑。
     */
    protected function shouldSkipLogging(Request $request): bool
    {
        if (UpgradeFreezeLock::isFrozen() && ! app(MaintenanceMode::class)->isWhitelisted($request)) {
            return true;
        }

        foreach ($this->excludedPaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断是否需要跳过记录响应内容
     */
    protected function shouldSkipResponse(Request $request): bool
    {
        foreach ($this->excludeResponsePaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录 API 请求日志
     */
    protected function logApiRequest(Request $request, array $logData): void
    {
        LogBuffer::add(ApiLog::class, array_merge($logData, [
            'user_id' => Auth::guard('api')->user()->user_id ?? $request->attributes->get('authenticated_user_id'),
            'version' => $this->getApiVersion($request),
        ]));
    }

    /**
     * 记录管理员请求日志
     */
    protected function logAdminRequest(Request $request, array $logData): void
    {
        LogBuffer::add(AdminLog::class, array_merge($logData, [
            'admin_id' => Auth::guard('admin')->id(),
            'module' => $this->getModule($request),
            'action' => $this->getAction($request),
        ]));
    }

    /**
     * 记录用户请求日志
     */
    protected function logUserRequest(Request $request, array $logData): void
    {
        LogBuffer::add(UserLog::class, array_merge($logData, [
            'user_id' => Auth::guard('user')->id(),
            'module' => $this->getModule($request),
            'action' => $this->getAction($request),
        ]));
    }

    /**
     * 记录回调日志
     */
    protected function logCallbackRequest(array $logData): void
    {
        LogBuffer::add(CallbackLog::class, $logData);
    }

    /**
     * 处理插件日志
     */
    protected function handlePluginLog(Request $request, array $logData): bool
    {
        $path = $request->path();
        $handlers = app()->tagged('plugin.log_handlers');
        foreach ($handlers as $handler) {
            if ($handler instanceof PluginLogHandler && $handler->shouldHandle($path)) {
                $handler->handle($logData);

                return true;
            }
        }

        return false;
    }

    /**
     * 获取 API 版本
     */
    protected function getApiVersion(Request $request): string
    {
        if ($request->is('api/V1/*')) {
            return 'v1';
        } elseif ($request->is('api/v2/*')) {
            return 'v2';
        } elseif ($request->is('api/acme/*')) {
            return 'acme';
        } elseif ($request->is(['api/deploy', 'api/deploy/*'])) {
            return 'deploy';
        }

        return 'v2';
    }

    /**
     * 获取模块名称
     */
    protected function getModule(Request $request): string
    {
        $routeAction = $request->route()?->getAction();
        if (isset($routeAction['controller'])) {
            $controller = class_basename($routeAction['controller']);
            $controller = str_replace('Controller', '', $controller);

            $parts = explode('@', $controller);

            return $parts[0];
        }

        return 'Unknown';
    }

    /**
     * 获取操作名称
     */
    protected function getAction(Request $request): string
    {
        $routeAction = $request->route()?->getAction();
        if (isset($routeAction['controller'])) {
            $parts = explode('@', $routeAction['controller']);

            return $parts[1] ?? 'Unknown';
        }

        return 'Unknown';
    }
}
