<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FilterUserIdParameter
{
    /**
     * 处理请求，移除 user_id 参数
     *
     * 纵深防御：从所有输入源剥离客户端注入的 user_id，
     * 使控制器无论用 input() / post() / query() 都拿不到注入值。
     */
    public function handle(Request $request, Closure $next)
    {
        // POST form / 默认 request bag
        $request->request->remove('user_id');

        // GET query bag
        $request->query->remove('user_id');

        // application/json 请求体（input()/all() 在 JSON 请求下读的是 json bag，
        // 上面两处清不到，需单独处理）
        if ($request->isJson()) {
            $request->json()->remove('user_id');
        }

        // 如果路由参数中有 user_id 并且不是路由中定义的必要参数，移除它
        $route = $request->route();
        if ($route) {
            $parameters = $route->parameters();
            $compiledRoute = $route->getCompiled();

            // 检查 user_id 是否为路由必需参数
            if (isset($parameters['user_id']) && ! in_array('user_id', $compiledRoute->getPathVariables())) {
                $route->forgetParameter('user_id');
            }
        }

        return $next($request);
    }
}
