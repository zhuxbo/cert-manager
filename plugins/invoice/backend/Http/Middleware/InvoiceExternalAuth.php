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

        $provided = $request->bearerToken() ?: (string) $request->query('token', '');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            $this->error('Invalid token');
        }

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
