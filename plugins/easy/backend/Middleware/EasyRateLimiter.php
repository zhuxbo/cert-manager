<?php

declare(strict_types=1);

namespace Plugins\Easy\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Easy 公开端点限流（无认证，凭 tid+email 自证）。
 *
 * 这些端点（check/apply/revalidate/sync/update-validation-method/validate-file/download）
 * 可回显证书私钥与部署命令，业务层仅靠 tid+email 组合自证。若无限流，攻击者可对
 * tid/email 空间爆破或滥用。这里在执行业务前做双维度计数拦截：
 *  - tid 维度：锁定单个订单号，防止对某 tid 穷举 email；合法用户轮询状态留足额度
 *  - IP 维度：粗粒度上限，防止单 IP 对大量不同 tid 枚举
 *
 * 跨 action 共享计数命名空间（不按 action 分桶），避免攻击者换接口绕过。
 * 与主系统 VerifyCodeRateLimiter 同源思路（Laravel RateLimiter facade + 双维度）。
 */
class EasyRateLimiter
{
    use ApiResponse;

    /** tid 维度：每分钟最大请求数 */
    public const DEFAULT_TID_MAX = 30;

    /** IP 维度：每分钟最大请求数 */
    public const DEFAULT_IP_MAX = 60;

    /** 计数衰减窗口（分钟） */
    public const DEFAULT_DECAY_MINUTES = 1;

    public function handle(Request $request, Closure $next): mixed
    {
        $config = $this->getConfig();
        $decaySeconds = $config['decay'] * 60;

        // tid 维度（仅在传了 tid 时启用，未传时由 IP 维度兜底）
        $tid = trim((string) ($request->input('tid') ?? ''));
        if ($tid !== '') {
            $tidKey = 'easy_rl:tid:'.sha1($tid);
            if (RateLimiter::tooManyAttempts($tidKey, $config['tid_max'])) {
                $this->error('操作过于频繁，请'.RateLimiter::availableIn($tidKey).'秒后再试');
            }
            RateLimiter::hit($tidKey, $decaySeconds);
        }

        // IP 维度（粗粒度上限，防枚举）
        $ip = $request->ip() ?? '127.0.0.1';
        $ipKey = 'easy_rl:ip:'.$ip;
        if (RateLimiter::tooManyAttempts($ipKey, $config['ip_max'])) {
            $this->error('操作过于频繁，请'.RateLimiter::availableIn($ipKey).'秒后再试');
        }
        RateLimiter::hit($ipKey, $decaySeconds);

        return $next($request);
    }

    /**
     * 限流配置：常量默认 + 可选 system_setting 覆盖（site.easyRateLimit*）。
     *
     * 运维可在站点设置注入 easyRateLimitTidMax / easyRateLimitIpMax /
     * easyRateLimitDecay 调整，不配置时走常量。
     */
    protected function getConfig(): array
    {
        return [
            'tid_max' => $this->positiveSetting('easyRateLimitTidMax', self::DEFAULT_TID_MAX),
            'ip_max' => $this->positiveSetting('easyRateLimitIpMax', self::DEFAULT_IP_MAX),
            'decay' => $this->positiveSetting('easyRateLimitDecay', self::DEFAULT_DECAY_MINUTES),
        ];
    }

    /**
     * 读取站点设置中的正整数限流参数，缺失/非法时回落到默认值。
     */
    protected function positiveSetting(string $key, int $default): int
    {
        $value = get_system_setting('site', $key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }
}
