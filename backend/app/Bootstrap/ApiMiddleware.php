<?php

namespace App\Bootstrap;

use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminRefreshTokenAuthenticate;
use App\Http\Middleware\ApiAuthenticate;
use App\Http\Middleware\DeployAuthenticate;
use App\Http\Middleware\DynamicCors;
use App\Http\Middleware\FilterUserIdParameter;
use App\Http\Middleware\FlushLogs;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LoginRateLimiter;
use App\Http\Middleware\LogOperation;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\RateLimiter;
use App\Http\Middleware\TimezoneMiddleware;
use App\Http\Middleware\TokenPreParser;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\UserAuthenticate;
use App\Http\Middleware\UserRefreshTokenAuthenticate;
use App\Http\Middleware\VerifyCodeRateLimiter;
use Illuminate\Foundation\Configuration\Middleware as Config;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;

class ApiMiddleware
{
    /**
     * 注册中间件
     */
    public function handle(Config $middleware): void
    {
        // 全局中间件
        // MaintenanceMode 必须排在 LogOperation 之前：freeze 期非白名单请求由
        // MaintenanceMode 直接返回 503，不再进入 LogOperation；同时 ForceJsonResponse
        // 已就位，503 也是 JSON 格式。
        $middleware->use([
            TrustProxies::class,
            DynamicCors::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            TimezoneMiddleware::class,
            ForceJsonResponse::class,
            MaintenanceMode::class,
            LogOperation::class,
            FlushLogs::class,
        ]);

        // 全局路由中间件组 在 RouteServiceProvider 中全局加载
        $middleware->group('global', [
            SubstituteBindings::class,
        ]);

        // API V1 中间件组
        $middleware->group('api.v1', [
            TokenPreParser::class,
            RateLimiter::class.':v1',
            ApiAuthenticate::class,
        ]);

        // API V2 中间件组
        $middleware->group('api.v2', [
            TokenPreParser::class,
            RateLimiter::class.':v2',
            ApiAuthenticate::class,
        ]);

        // API Deploy 中间件组
        $middleware->group('api.deploy', [
            RateLimiter::class.':deploy',
            DeployAuthenticate::class,
        ]);

        // 管理员中间件组
        $middleware->group('api.admin', [
            AdminAuthenticate::class,
        ]);

        // 管理员刷新Token
        $middleware->group('api.admin.refresh', [
            AdminRefreshTokenAuthenticate::class,
        ]);

        // 用户中间件组
        $middleware->group('api.user', [
            UserAuthenticate::class,
            FilterUserIdParameter::class,
        ]);

        // 用户刷新Token
        $middleware->group('api.user.refresh', [
            UserRefreshTokenAuthenticate::class,
        ]);

        // 中间件别名
        $middleware->alias([
            'signed' => ValidateSignature::class,
            'throttle' => RateLimiter::class,
            'login.limiter' => LoginRateLimiter::class,
            'verify.code.limiter' => VerifyCodeRateLimiter::class,
        ]);
    }
}
