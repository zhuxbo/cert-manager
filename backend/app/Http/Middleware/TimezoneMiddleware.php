<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TimezoneMiddleware
{
    /**
     * 仅修改 app.timezone 配置（影响 serializeDate 输出按用户时区），
     * 不再调 date_default_timezone_set —— 写入侧由系统时区固定，跨时区部署一致。
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $request->header('X-Timezone');

        if ($timezone && $timezone !== config('app.timezone')) {
            config(['app.timezone' => $timezone]);
        }

        return $next($request);
    }
}
