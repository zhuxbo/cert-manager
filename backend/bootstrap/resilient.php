<?php

/**
 * bootstrap/cache 编译清单（services.php / packages.php）TOCTOU 兜底重试器。
 *
 * 这两个文件是框架启动早期（注册 provider 之前）由 ProviderRepository / PackageManifest
 * 读取的编译缓存。以下场景会在「检查存在(is_file) → 读取(require)」之间让文件短暂消失，
 * 抛 "Failed to open stream: No such file"：
 *   - 多进程首次并发编译（多 FPM worker 冷启动 / paratest 多 worker）互相 rename 覆盖；
 *   - 升级 optimize:clear → optimize 之间的窗口，期间请求进来触发即时编译；
 *   - 部分文件系统（VirtioFS / overlay / 网络盘）rename 非原子。
 *
 * 该错误发生在框架 bootstrap 极早期——异常处理器尚未注册（应用层 try/catch 够不着），
 * 且尚无任何输出/副作用——故清掉半态缓存、退避重试整个启动是安全且确定性自愈的。
 *
 * 返回执行器：fn(callable $run, string $cacheDir): mixed
 *   $run      —— 触发 bootstrap 的闭包（handleRequest / handleCommand / createApplication）
 *   $cacheDir —— bootstrap/cache 绝对路径（用于清半态清单）
 */

return static function (callable $run, string $cacheDir) {
    // 仅识别 bootstrap 编译清单的读失败，避免误重试业务异常（遍历异常链兜底 wrap 场景）
    $matches = static function (Throwable $e): bool {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $m = $x->getMessage();
            if ((str_contains($m, 'services.php') || str_contains($m, 'packages.php'))
                && str_contains($m, 'cache')
                && (str_contains($m, 'Failed to open stream')
                    || str_contains($m, 'No such file')
                    || str_contains($m, 'does not exist'))) {
                return true;
            }
        }

        return false;
    };

    $attempt = 0;

    while (true) {
        try {
            return $run();
        } catch (Throwable $e) {
            if (! $matches($e) || ++$attempt > 3) {
                throw $e;
            }

            // 清掉可能半写/损坏的编译清单，重试时由框架重新编译生成
            foreach (['services.php', 'packages.php'] as $file) {
                @unlink($cacheDir.'/'.$file);
            }

            // 退避 30/60/90ms，给并发写方留出完成 rename 的时间
            usleep(30000 * $attempt);
        }
    }
};
