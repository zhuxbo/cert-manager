<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class LoginRateLimiter
{
    use ApiResponse;

    /**
     * 处理传入的请求
     *
     * @param  string  $guard  认证守卫名称 (admin|user)
     */
    public function handle(Request $request, Closure $next, string $guard): mixed
    {
        $key = $guard.':'.$this->getRequestIpAddress($request);
        // 归一化 account（小写 + 去空白）再拼 key：DB 账号匹配（username/email/mobile）在 ci collation 下大小写不敏感，
        // 同一账号的大小写/空白变体本就是同一用户，归一化后共用同一限流桶，防变体分散绕过爆破限制（不会误并不同用户）
        $account = mb_strtolower(trim($request->input('account') ?? ''));
        if ($account !== '') {
            $key = $guard.':'.$account;
        }
        // 独立累计失败计数，用于账号锁定，不受短期限流窗口影响
        $lockoutCounterKey = $key.':lockout';

        $limiterConfig = $this->getLimiterConfig($guard);

        // 检查该账号是否被锁定
        if (Cache::has($key.'_locked')) {
            $this->error('您的账号已被锁定，请联系管理员或稍后再试');
        }

        // 限流窗口配置
        $maxAttemptsPerWindow = $limiterConfig['max_attempts_per_window'];
        $decayMinutes = $limiterConfig['decay_minutes'];
        $lockoutAttempts = $limiterConfig['lockout_attempts'];
        $lockoutMinutes = $limiterConfig['lockout_minutes'];
        $lockoutCounterDecayMinutes = $limiterConfig['lockout_counter_decay_minutes'];

        // 检查是否超过限制 (在执行实际登录前)
        // 注意：tooManyAttempts 与 hit 之间存在轻微 TOCTOU 竞态，高并发下可能被多绕过 1-2 次请求，
        // 对登录场景影响可忽略，如需严格原子性可改用 Redis Lua 脚本。
        if (RateLimiter::tooManyAttempts($key, $maxAttemptsPerWindow)) {
            $seconds = RateLimiter::availableIn($key);
            $this->error('尝试次数过多，请'.$seconds.'秒后再试');
        }

        $response = $next($request);

        // $response 是 Illuminate\\Http\\JsonResponse (因为控制器抛出 ApiResponseException)
        $responseData = $response->getData(true);

        $code = isset($responseData['code']) ? (int) $responseData['code'] : null;

        if ($code === 1) {
            // 登录成功，重置 RateLimiter 计数器 和 Cache 锁定标记 (如果存在)
            RateLimiter::clear($key);
            RateLimiter::clear($lockoutCounterKey);
            Cache::forget($key.'_locked');
        } elseif ($code === 0) {
            // 登录失败，增加计数器（仅业务逻辑错误，验证错误等不计入）
            RateLimiter::hit($key, $decayMinutes * 60);
            RateLimiter::hit($lockoutCounterKey, $lockoutCounterDecayMinutes * 60);

            $attempts = (int) RateLimiter::attempts($lockoutCounterKey);
            // 如果失败次数累计达到锁定阈值，锁定账号
            if ($attempts >= $lockoutAttempts) {
                Cache::put($key.'_locked', true, now()->addMinutes($lockoutMinutes));
                // 锁定后清理计数器，避免解锁后继承旧失败次数
                RateLimiter::clear($key);
                RateLimiter::clear($lockoutCounterKey);
                $this->error('您的账号已被锁定，请联系管理员或稍后再试');
            }
        }
        // 对于其他类型的失败 (例如验证错误，通常有不同的HTTP状态码或没有 'code' 字段)，
        // 不计入登录失败尝试。

        return $response;
    }

    /**
     * 获取请求IP地址
     */
    protected function getRequestIpAddress(Request $request): string
    {
        return $request->ip() ?? '127.0.0.1';
    }

    /**
     * 获取限流配置（支持默认配置 + guard 覆盖）
     */
    protected function getLimiterConfig(string $guard): array
    {
        $defaultConfig = config('auth.login_rate_limiter.default', []);
        $guardConfig = config("auth.login_rate_limiter.guards.$guard", []);
        $config = array_merge($defaultConfig, is_array($guardConfig) ? $guardConfig : []);

        return [
            'max_attempts_per_window' => max(1, (int) ($config['max_attempts_per_window'] ?? 5)),
            'decay_minutes' => max(1, (int) ($config['decay_minutes'] ?? 10)),
            'lockout_attempts' => max(1, (int) ($config['lockout_attempts'] ?? 10)),
            'lockout_minutes' => max(1, (int) ($config['lockout_minutes'] ?? 24 * 60)),
            'lockout_counter_decay_minutes' => max(1, (int) ($config['lockout_counter_decay_minutes'] ?? 24 * 60)),
        ];
    }
}
