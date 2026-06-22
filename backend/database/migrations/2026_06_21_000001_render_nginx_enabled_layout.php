<?php

use App\Services\Nginx\NginxRenderer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $projectRoot = dirname(base_path());
        $render = "$projectRoot/nginx/render.sh";
        $enabledRoutes = "$projectRoot/nginx/enabled/routes";
        // 自愈"旧后台升级器（无 render 逻辑）→ 新三层布局"过渡：仅当部署形态（render.sh 存在）
        // 且 enabled 尚未渲染时执行。CI/dev 无此布局 → 守卫跳过、零副作用。NginxRenderer 内部非致命。
        if (! is_file($render) || is_dir($enabledRoutes)) {
            return;
        }
        app(NginxRenderer::class)->render($projectRoot);
    }

    public function down(): void
    {
        // no-op：渲染产物不回滚
    }
};
