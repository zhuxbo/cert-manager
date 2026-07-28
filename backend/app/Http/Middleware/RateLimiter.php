<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\DeployToken;
use App\Support\ApiErrorCode;
use App\Traits\ApiResponse;
use App\Traits\ExtractsToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RateLimiter
{
    use ApiResponse;
    use ExtractsToken;

    /**
     * 限流中间件 - 在认证之前执行
     */
    public function handle(Request $request, Closure $next, string $limiter = 'v2')
    {
        // 1. 优先检查 token 级别的限流
        // acme 没有有效的 token，deploy 使用 DeployToken
        if ($limiter === 'acme') {
            $this->checkIpRateLimit($request, $limiter);
        } elseif ($limiter === 'deploy') {
            $hasValidToken = $this->checkDeployTokenRateLimit($request);
            if (! $hasValidToken) {
                $this->checkIpRateLimit($request, $limiter);
            }
        } else {
            $hasValidToken = $this->checkTokenRateLimit($request, $limiter);
            if (! $hasValidToken) {
                $this->checkIpRateLimit($request, $limiter);
            }
        }

        return $next($request);
    }

    /**
     * 基于 IP 的基础限流
     */
    private function checkIpRateLimit(Request $request, string $limiter): void
    {
        $ip = $request->ip();
        $key = sprintf('rate_limit_ip:%s:%s', $limiter, $ip);

        // IP 限流相对宽松，主要防止暴力攻击
        $limit = match ($limiter) {
            'v1', 'v2', 'deploy', 'acme' => 120,
            'enterprise-lookup' => 30,
            'zipcode-lookup' => 60,
            default => 60,
        };

        $this->checkLimit($key, $limit, 'IP rate limit exceeded');
    }

    /**
     * 基于 Token 的精确限流
     *
     * @return bool 是否找到有效的 token
     */
    private function checkTokenRateLimit(Request $request, string $limiter): bool
    {
        // 尝试从请求属性中获取预解析的 token 信息
        /** @var ApiToken|null $apiToken */
        $apiToken = $request->attributes->get('api_token_info');

        if (! $apiToken) {
            // 如果没有预解析的信息，尝试直接提取和查找
            $token = $this->extractToken($request);
            if ($token) {
                $apiToken = ApiToken::where('token', hash('sha256', $token))->first();
            }
        }

        if (! $apiToken) {
            // 没有有效的 token，返回 false
            return false;
        }

        // 使用 token 配置的限流值
        $limit = $apiToken->getEffectiveRateLimit($this->getDefaultTokenLimit($limiter));
        $identifier = 'token_'.$apiToken->id;

        $key = sprintf('rate_limit_token:%s:%s', $limiter, $identifier);
        $this->checkLimit($key, $limit, 'Token rate limit exceeded');

        // 返回 true 表示找到了有效的 token
        return true;
    }

    /**
     * 基于 DeployToken 的限流
     *
     * @return bool 是否找到有效的 token
     */
    private function checkDeployTokenRateLimit(Request $request): bool
    {
        $token = $this->extractToken($request);
        if (! $token) {
            return false;
        }

        $deployToken = DeployToken::findByToken($token);
        if (! $deployToken) {
            return false;
        }

        // 使用 token 配置的限流值
        $limit = $deployToken->getEffectiveRateLimit(60);
        $identifier = 'deploy_token_'.$deployToken->id;

        $key = sprintf('rate_limit_deploy:%s', $identifier);
        $this->checkLimit($key, $limit, 'Deploy token rate limit exceeded');

        return true;
    }

    /**
     * 滑动窗口限流检查
     *
     * 用当前窗口 + 上一窗口加权估算，平滑窗口边界突发
     * 例：窗口 60s，限额 60 次，当前窗口已过 20s（剩余比例 66.7%）
     * 估算值 = 当前窗口计数 + 上一窗口计数 × 66.7%
     *
     * 用 now()->timestamp 而非 time()：前者可被 Carbon::setTestNow 控制，
     * 让滑动窗口测试能冻结时间避免 60+1 次循环跨窗口边界（边界跨越会让
     * estimated 被 prev 权重稀释到 limit 以下，导致限流测试 flaky）。
     */
    private function checkLimit(string $key, int $limit, string $errorMessage): void
    {
        $window = 60;
        $now = now()->timestamp;
        $currentWindow = (int) floor($now / $window);
        $elapsed = $now % $window;
        $prevWeight = 1 - $elapsed / $window;

        $currentKey = "$key:$currentWindow";
        $prevKey = "$key:".($currentWindow - 1);

        // 当前窗口计数器，TTL 设为 2 个窗口确保上一窗口数据可用
        Cache::add($currentKey, 0, $window * 2);
        $currentCount = Cache::increment($currentKey);
        $prevCount = (int) Cache::get($prevKey, 0);

        $estimated = $prevCount * $prevWeight + $currentCount;

        if ($estimated > $limit) {
            // 机器可读标识：错误响应固定 HTTP 200 + code=0（全站统一契约，v1/v2/acme/deploy 共用
            // 本出口），客户端只能靠 error_code 区分"确定性限流"与网络错误。
            // 刻意不改 429：客户端把 429 认作可重试，指数退避 1s→2s→4s 全落在同一 60s 窗口内注定
            // 全失败，且上面的 Cache::increment 在阈值判断之前，每次重试都继续推高计数器、把恢复
            // 时间往后拖。返回 200 让客户端不重试，反而是对的。
            //
            // retry_after 取「跨过下一个整窗口」而非「当前窗口剩余」：本方法用滑动窗口加权判定，
            // 只睡到下一窗口起点时 elapsed=0 → prevWeight=1 → 刚刚超限的那个计数全额计入，
            // estimated 必然仍超限、必再被拒一次，而那次重试又会把计数器垫高、把恢复时间继续
            // 往后推。多睡一个窗口后，prev 指向的是中间那个（客户端不再请求即为 0）窗口，
            // estimated 归零，睡够即可重试的语义才成立。
            $this->error($errorMessage, [
                'error_code' => ApiErrorCode::RATE_LIMITED,
                'retry_after' => $window * 2 - $elapsed,
            ]);
        }
    }

    /**
     * 获取默认 Token 限流数量
     */
    private function getDefaultTokenLimit(string $limiter): int
    {
        return match ($limiter) {
            'v2' => 60,
            default => 30,
        };
    }
}
