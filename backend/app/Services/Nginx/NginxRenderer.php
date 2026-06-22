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
        // 并发排空 stdout + stderr 防 proc_open 管道死锁：子进程向任一管道写满缓冲（Linux ~64KB）
        // 即阻塞，父进程串行「先读 stdout 到 EOF」会与之互锁。仅 stderr 留作失败诊断、stdout 丢弃。
        $stderr = $this->drainPipes($pipes[1], $pipes[2]);
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

    /**
     * 并发排空子进程的 stdout + stderr 两个管道，返回 stderr 全文（stdout 排空即丢弃）。
     *
     * proc_open 声明两管道时，父进程若串行「先读 stdout 到 EOF 再读 stderr」，子进程一旦向
     * stderr 写满管道缓冲（Linux ~64KB）就阻塞在写、父进程又阻塞在读 stdout 等永不到来的 EOF
     * —— 经典 proc_open 死锁（如 bash xtrace 把每条命令叙述到 stderr，量随 render 内容线性增长）。
     * 用 stream_select 轮询两管道、哪个就绪读哪个直到双双 EOF，杜绝任一方向写满阻塞。
     * 此处需要 stderr 文本供失败诊断日志，故保留 stderr、丢弃 stdout（与 BinaryLocator::drainPipes
     * 取向相反）；不复用 BinaryLocator 是为自包含——升级半换码进程里它可能是无此方法的旧类。
     *
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private function drainPipes($stdout, $stderr): string
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $err = '';
        $open = [1 => $stdout, 2 => $stderr];

        while ($open !== []) {
            $read = $open;
            $write = $except = [];

            // null 超时：阻塞等任一管道就绪（不忙等）。被信号打断返回 false 时退出，由调用方
            // fclose + proc_close 收尾（关读端令子进程写 stderr 得 EPIPE 自终，不残留死锁）。
            if (@stream_select($read, $write, $except, null) === false) {
                break;
            }

            foreach ($read as $fd => $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    // 非阻塞下「就绪却空读」即 EOF：移出待读集；非 EOF 的偶发空读留待下轮
                    if (feof($stream)) {
                        unset($open[$fd]);
                    }

                    continue;
                }
                if ($fd === 2) {
                    $err .= $chunk;
                }
            }
        }

        return $err;
    }
}
