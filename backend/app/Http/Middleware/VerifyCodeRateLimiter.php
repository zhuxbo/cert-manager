<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 无需认证的验证码/账户接口限流（register / reset-password / send-email-code）。
 *
 * 双维度计数：
 *  - email 维度：防止换 IP 绕过 + NAT 共享出口下不会误伤同一 IP 后多个真实用户
 *  - IP 维度：粗粒度上限，防止单 IP 对大量不同邮箱发起轰炸 / 枚举
 *
 * 在执行实际业务前计数（与 LoginRateLimiter 的"成功重置"语义不同）：
 * 这些端点没有干净的成功/失败 code 可区分（reset 已统一返回模糊响应），
 * 因此对每次请求都计数，达到阈值即拦截。
 */
class VerifyCodeRateLimiter
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, string $action = 'verify_code'): mixed
    {
        $email = (string) ($request->input('email') ?? '');
        $ip = $request->ip() ?? '127.0.0.1';

        $config = $this->getConfig($action);

        // email 维度（仅在传了 email 时启用，未传时由 IP 维度兜底）
        if ($email !== '') {
            $emailKey = sprintf('verify_code_rl:%s:email:%s', $action, mb_strtolower($email));
            if (RateLimiter::tooManyAttempts($emailKey, $config['email_max'])) {
                $this->error('操作过于频繁，请'.RateLimiter::availableIn($emailKey).'秒后再试');
            }
            RateLimiter::hit($emailKey, $config['email_decay'] * 60);
        }

        // IP 维度（粗粒度上限）
        $ipKey = sprintf('verify_code_rl:%s:ip:%s', $action, $ip);
        if (RateLimiter::tooManyAttempts($ipKey, $config['ip_max'])) {
            $this->error('操作过于频繁，请'.RateLimiter::availableIn($ipKey).'秒后再试');
        }
        RateLimiter::hit($ipKey, $config['ip_decay'] * 60);

        return $next($request);
    }

    /**
     * 限流配置（支持默认 + 按 action 覆盖）。
     */
    protected function getConfig(string $action): array
    {
        $default = config('auth.verify_code_rate_limiter.default', []);
        $override = config("auth.verify_code_rate_limiter.actions.$action", []);
        $config = array_merge($default, is_array($override) ? $override : []);

        return [
            'email_max' => max(1, (int) ($config['email_max'] ?? 5)),
            'email_decay' => max(1, (int) ($config['email_decay'] ?? 10)),
            'ip_max' => max(1, (int) ($config['ip_max'] ?? 20)),
            'ip_decay' => max(1, (int) ($config['ip_decay'] ?? 10)),
        ];
    }
}
