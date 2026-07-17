<?php

namespace App\Http\Middleware;

use App\Models\Acme;
use App\Models\ApiToken;
use App\Models\Callback;
use App\Models\CnameDelegation;
use App\Models\Contact;
use App\Models\DeployToken;
use App\Models\Fund;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Organization;
use App\Models\Scopes\UserScope;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Auth\TokenGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class ApiAuthenticate
{
    use ApiResponse;

    /**
     * API 认证中间件
     */
    public function handle(Request $request, Closure $next)
    {
        /** @var TokenGuard $guard */
        $guard = Auth::guard('api');

        if (! $guard->check()) {
            $this->error('Unauthorized');
        }

        /** @var ApiToken $apiToken */
        $apiToken = $guard->user();

        if (! $apiToken->status) {
            $this->error('Api token is disabled');
        }

        if ($apiToken->user_id && (! $apiToken->user instanceof User || $apiToken->user->status === 0)) {
            $this->error('Account is disabled');
        }

        if (! $apiToken->isIpAllowed($request->ip())) {
            $this->error('IP is not allowed');
        }

        // 异步更新最后使用信息
        $tokenId = $apiToken->id;
        $ip = $request->ip();
        App::terminating(function () use ($tokenId, $ip) {
            ApiToken::withoutTimestamps(function () use ($tokenId, $ip) {
                ApiToken::where('id', $tokenId)->update([
                    'last_used_ip' => $ip,
                    'last_used_at' => now(),
                ]);
            });
        });

        if ($apiToken->user_id) {
            UserScope::addScopeToModels($apiToken->user_id, [
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

        $request->attributes->set('authenticated_api_token', $apiToken);

        return $next($request);
    }
}
