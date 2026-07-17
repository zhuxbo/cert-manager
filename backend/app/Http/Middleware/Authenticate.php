<?php

namespace App\Http\Middleware;

use App\Models\Acme;
use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\Callback;
use App\Models\CnameDelegation;
use App\Models\Contact;
use App\Models\DeployToken;
use App\Models\ErrorLog;
use App\Models\Fund;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Organization;
use App\Models\Scopes\UserScope;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LogBuffer;
use App\Traits\ApiResponse;
use App\Utils\LogScrubber;
use Closure;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\JWT;
use Tymon\JWTAuth\JWTGuard;

abstract class Authenticate
{
    use ApiResponse;

    /**
     * 返回对应的 Guard 名称
     */
    abstract protected function guardName(): string;

    /**
     * 中间件入口
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard($this->guardName());

        if (! $guard->check()) {
            throw new AuthenticationException('Unauthorized');
        }

        /** @var Admin|User $user */
        $user = $guard->user();
        if ($user->status === 0) {
            $guard->logout();
            throw new AuthenticationException('Account is disabled');
        }

        // 检查黑名单
        try {
            $jwt = app(JWT::class);
            $token = $jwt->parseToken();
            $payload = $token->getPayload();

            // Tymon JWT 的内置黑名单检查
            if ($jwt->blacklist()->has($payload)) {
                throw new AuthenticationException('Token is blacklisted');
            }

            // 检查永久黑名单 (token_version) + 宽限期
            $this->checkTokenVersionGraceful($guard, $payload);
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (Exception $e) {
            LogBuffer::add(ErrorLog::class, [
                'method' => $request->method(),
                'url' => LogScrubber::scrubUrl($request->fullUrl()),
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            throw new AuthenticationException('Authentication failed');
        }

        // 限制查询当前用户的数据
        if ($this->guardName() === 'user' && $guard->id()) {
            UserScope::addScopeToModels($guard->id(), [
                Acme::class,
                ApiToken::class,
                Callback::class,
                CnameDelegation::class,
                DeployToken::class,
                Order::class,
                Fund::class,
                Transaction::class,
                Organization::class,
                Contact::class,
                OrderDocument::class,
            ]);
        }

        return $next($request);
    }

    /**
     * 检查 token_version + “黑名单宽限期”
     *
     * @throws AuthenticationException
     */
    protected function checkTokenVersionGraceful(JWTGuard $guard, $payload): void
    {
        // 从 Token 中获取版本号
        $tokenVersion = $payload->get('token_version') ?? 0;

        // 从数据库模型获取当前用户版本号 和 登出时间
        $user = $guard->user();
        $userVersion = $user->token_version ?? 0;
        $logoutAt = $user->logout_at->timestamp ?? 0;

        // 如果用户模型版本号 > Token 中的版本号，说明是旧令牌，需要进入“宽限期检查”
        if ($userVersion > $tokenVersion) {
            $gracePeriod = config('jwt.blacklist_grace_period', 30);

            // 若“现在 - 登出时间” 已经超过宽限期，则立即返回 Unauthorized
            if ((time() - $logoutAt) > $gracePeriod) {
                throw new AuthenticationException('Unauthorized');
            }
        }
    }
}
