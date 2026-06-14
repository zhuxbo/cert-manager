<?php

declare(strict_types=1);

namespace Plugins\Invoice\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Plugins\Invoice\Services\InvoiceConfig;

class InvoiceExternalAuth
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): mixed
    {
        $expected = InvoiceConfig::get('external_token');
        if (! is_string($expected) || $expected === '') {
            $this->error('外部接入未启用');
        }

        // 仅接受 Authorization: Bearer 头：长效 token 绝不走 URL query
        // （否则会落入 nginx/代理/对接方 access log，违反"凭据不进 URL"基线）
        $provided = (string) $request->bearerToken();
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            $this->error('Invalid token');
        }

        // IP 白名单为可选的纵深防御（主鉴权是上面的 token）。注意 $request->ip() 取 REMOTE_ADDR：
        // 本项目 TrustProxies 未配置可信代理，当前 nginx→PHP-FPM(fastcgi) 单层拓扑下它即真实客户端 IP，白名单正常工作。
        // 若在 nginx 前再叠 CDN/反向代理，必须先在 bootstrap/app.php 配置 trustProxies()，
        // 否则 $request->ip() 会变成上游代理 IP，导致白名单恒失败或被绕过。
        $allowedIps = InvoiceConfig::get('external_allowed_ips', '');
        if (is_string($allowedIps) && $allowedIps !== '') {
            $whitelist = array_filter(array_map('trim', explode(',', $allowedIps)));
            if (! empty($whitelist) && ! in_array($request->ip(), $whitelist, true)) {
                $this->error('IP not allowed');
            }
        }

        return $next($request);
    }
}
