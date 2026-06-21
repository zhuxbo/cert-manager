<?php

namespace App\Services\Nginx;

use App\Services\Binary\BinaryLocator;
use Illuminate\Support\Facades\Log;

class NginxRenderer
{
    /**
     * 运行 <projectRoot>/nginx/render.sh（不 reload），纯文件操作。
     * 非致命：失败记日志并返回 false。
     *
     * 半换码升级进程里 BinaryLocator 可能是旧类（无 bash() 方法），故 try/catch 兜底到 /bin/bash。
     */
    public function render(string $projectRoot): bool
    {
        $script = "$projectRoot/nginx/render.sh";
        if (! is_file($script)) {
            Log::warning("nginx render.sh 不存在，跳过渲染: $script");

            return false;
        }

        // 半换码升级进程里 BinaryLocator 可能是旧类（无 bash() 方法），故 try/catch 兜底到 /bin/bash
        try {
            $bash = app(BinaryLocator::class)->bash();
        } catch (\Throwable $e) {
            $bash = is_file('/bin/bash') ? '/bin/bash' : (is_file('/usr/bin/bash') ? '/usr/bin/bash' : 'bash');
        }

        $proc = @proc_open([$bash, $script, $projectRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            Log::error('nginx render 启动失败（proc_open）');
            foreach ($pipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }

            return false;
        }
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $p) {
            fclose($p);
        }
        if (($code = proc_close($proc)) !== 0) {
            Log::error("nginx render 失败（code={$code}）: $stderr");

            return false;
        }
        Log::info('nginx 路由配置已渲染');

        return true;
    }
}
