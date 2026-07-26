<?php

namespace App\Http\Middleware;

use App\Models\Acme;
use App\Models\DeployToken;
use App\Models\Order;
use App\Models\Scopes\UserScope;
use App\Models\User;
use App\Support\ApiErrorCode;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class DeployAuthenticate
{
    use ApiResponse;

    /**
     * Deploy API 认证中间件
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?: $request->query('token');

        // 认证失败一律 HTTP 200 + code=0（全站统一契约），故靠 errors.error_code 给客户端机器可读
        // 分类：这些都是确定性失败（需人工换 token / 放开 IP），客户端据此停止而非当网络错误每日重试。
        if (empty($token)) {
            $this->error('Unauthorized', ['error_code' => ApiErrorCode::TOKEN_MISSING]);
        }

        $deployToken = DeployToken::findByToken($token);

        if (! $deployToken) {
            $this->error('Invalid token', ['error_code' => ApiErrorCode::TOKEN_INVALID]);
        }

        if (! $deployToken->status) {
            $this->error('Deploy token is disabled', ['error_code' => ApiErrorCode::TOKEN_DISABLED]);
        }

        if ($deployToken->user_id && (! $deployToken->user instanceof User || $deployToken->user->status === 0)) {
            $this->error('Account is disabled', ['error_code' => ApiErrorCode::ACCOUNT_DISABLED]);
        }

        if (! $deployToken->isIpAllowed($request->ip())) {
            $this->error('IP is not allowed', ['error_code' => ApiErrorCode::IP_NOT_ALLOWED]);
        }

        // 异步更新最后使用信息
        $tokenId = $deployToken->id;
        $ip = $request->ip();
        App::terminating(function () use ($tokenId, $ip) {
            DeployToken::withoutTimestamps(function () use ($tokenId, $ip) {
                DeployToken::where('id', $tokenId)->update([
                    'last_used_ip' => $ip,
                    'last_used_at' => now(),
                ]);
            });
        });

        // 应用 UserScope 限制查询范围
        // 注意：Cert 表没有 user_id 字段，通过 Order 关联限制
        if ($deployToken->user_id) {
            UserScope::addScopeToModels($deployToken->user_id, [
                Acme::class,
                Order::class,
            ]);
        }

        $request->attributes->set('authenticated_deploy_token', $deployToken);
        $request->attributes->set('authenticated_user_id', $deployToken->user_id);

        return $next($request);
    }
}
