<?php

namespace App\Http\Middleware;

use App\Models\Acme;
use App\Models\DeployToken;
use App\Models\Order;
use App\Models\Scopes\UserScope;
use App\Models\User;
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

        if (empty($token)) {
            $this->error('Unauthorized');
        }

        $deployToken = DeployToken::findByToken($token);

        if (! $deployToken) {
            $this->error('Invalid token');
        }

        if (! $deployToken->status) {
            $this->error('Deploy token is disabled');
        }

        if ($deployToken->user_id && (! $deployToken->user instanceof User || $deployToken->user->status === 0)) {
            $this->error('Account is disabled');
        }

        if (! $deployToken->isIpAllowed($request->ip())) {
            $this->error('IP is not allowed');
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
