<?php

namespace App\Console\Commands;

use App\Support\Opcache;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Process\Process;

class ClearAllCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-all
                            {--quick : 快速模式，不显示详细信息}
                            {--logs : 同时清除旧日志文件}
                            {--restart-queue : 清理完成后重启队列服务}
                            {--without-composer : 跳过 Composer autoload 缓存清理}
                            {--without-opcache : 跳过 OPcache 字节码缓存清理}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '彻底清除所有类型的缓存文件，包括Laravel缓存、Bootstrap缓存、存储缓存等，可选重启队列服务';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $quick = $this->option('quick');
        $clearLogs = $this->option('logs');
        $restartQueue = $this->option('restart-queue');
        $skipComposer = $this->option('without-composer');
        $skipOpcache = $this->option('without-opcache');
        if (! $quick) {
            $this->info('开始清除SSL证书管理系统所有缓存...');
            $this->newLine();
        }

        // 1. 清除Laravel应用缓存
        $this->clearLaravelCaches($quick);

        // 2. 清除Bootstrap缓存文件
        $this->clearBootstrapCaches($quick);

        // 3. 清除Storage框架缓存
        $this->clearStorageCaches($quick);

        // 4. 清除日志文件（可选）
        if ($clearLogs) {
            $this->clearOldLogs($quick);
        }

        // 5. 清除Composer autoload缓存
        if (! $skipComposer) {
            $this->clearComposerCache($quick);
        } elseif (! $quick) {
            $this->newLine();
            $this->warn('5. 已跳过 Composer autoload 缓存清理');
        }

        // 6. 清除 OPcache 字节码缓存
        if (! $skipOpcache) {
            $this->clearOpcache($quick);
        } elseif (! $quick) {
            $this->newLine();
            $this->warn('6. 已跳过 OPcache 字节码缓存清理');
        }

        // 7. 重启队列服务（可选）
        if ($restartQueue) {
            $this->restartQueueService($quick);
        }

        if (! $quick) {
            $this->newLine();
            $this->info('========================================');
            $this->info('所有缓存清除完成！');
            if ($restartQueue) {
                $this->info('队列服务已重启！');
            }
            $this->info('========================================');
            $this->newLine();

            $this->showCacheStatus();
            $this->showRecommendations($restartQueue);
        } else {
            $message = '✓ 所有缓存清除完成';
            if ($restartQueue) {
                $message .= '，队列服务已重启';
            }
            $this->info($message);
        }

        return CommandAlias::SUCCESS;
    }

    /**
     * 清除Laravel应用缓存
     */
    private function clearLaravelCaches(bool $quick): void
    {
        if (! $quick) {
            $this->info('1. 清除Laravel应用缓存');
            $this->info('============================================');
        }

        $caches = [
            'config:clear' => '配置缓存',
            'route:clear' => '路由缓存',
            'cache:clear' => '应用缓存',
            'event:clear' => '事件缓存',
        ];

        foreach ($caches as $command => $description) {
            try {
                Artisan::call($command);
                if (! $quick) {
                    $this->line("✓ {$description}清除成功");
                }
            } catch (Exception $e) {
                if (! $quick) {
                    $this->error("✗ {$description}清除失败: ".$e->getMessage());
                }
            }
        }
    }

    /**
     * 清除Bootstrap缓存文件
     */
    private function clearBootstrapCaches(bool $quick): void
    {
        if (! $quick) {
            $this->newLine();
            $this->info('2. 清除Bootstrap缓存文件');
            $this->info('============================================');
        }

        $bootstrapCachePath = base_path('bootstrap/cache');

        if (File::exists($bootstrapCachePath)) {
            try {
                // 获取所有文件，不仅仅是.php文件
                $allFiles = File::glob($bootstrapCachePath.'/*');
                $deletedCount = 0;
                $deletedFiles = [];

                foreach ($allFiles as $file) {
                    $fileName = basename($file);
                    // 排除.gitignore文件
                    if ($fileName !== '.gitignore' && File::isFile($file)) {
                        File::delete($file);
                        $deletedCount++;
                        $deletedFiles[] = $fileName;
                    }
                }

                if (! $quick) {
                    $this->line("✓ Bootstrap缓存文件清除完成 (删除 $deletedCount 个文件)");

                    if ($deletedCount > 0 && count($deletedFiles) <= 10) {
                        $this->line('  删除的文件: '.implode(', ', $deletedFiles));
                    }

                    $remainingFiles = count(File::glob($bootstrapCachePath.'/*')); // 排除.gitignore
                    if ($remainingFiles === 0) {
                        $this->line('✓ Bootstrap缓存目录已完全清空（保留.gitignore）');
                    } else {
                        $this->warn("⚠ Bootstrap缓存目录还有 $remainingFiles 个文件");
                    }
                }
            } catch (Exception $e) {
                if (! $quick) {
                    $this->error('✗ Bootstrap缓存清除失败: '.$e->getMessage());
                }
            }
        } else {
            if (! $quick) {
                $this->warn('⚠ bootstrap/cache目录不存在');
            }
        }
    }

    /**
     * 清除Storage框架缓存
     */
    private function clearStorageCaches(bool $quick): void
    {
        if (! $quick) {
            $this->newLine();
            $this->info('3. 清除Storage框架缓存');
            $this->info('============================================');
        }

        $storagePaths = [
            'storage/framework/cache/data' => 'Storage框架缓存',
            'storage/framework/views' => 'Compiled views',
            'storage/framework/sessions' => 'Sessions缓存',
        ];

        foreach ($storagePaths as $path => $description) {
            $fullPath = base_path($path);

            if (File::exists($fullPath)) {
                try {
                    if (File::isDirectory($fullPath)) {
                        $files = File::allFiles($fullPath);
                        $deletedCount = 0;

                        foreach ($files as $file) {
                            if ($file->getFilename() === '.gitignore') {
                                continue;
                            }

                            File::delete($file->getPathname());
                            $deletedCount++;
                        }

                        if (! $quick) {
                            $this->line("✓ {$description}清除完成 (删除 $deletedCount 个文件)");
                        }
                    }
                } catch (Exception $e) {
                    if (! $quick) {
                        $this->error("✗ {$description}清除失败: ".$e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * 清除旧日志文件
     */
    private function clearOldLogs(bool $quick): void
    {
        if (! $quick) {
            $this->newLine();
            $this->info('4. 清除旧日志文件');
            $this->info('============================================');
        }

        $logsPath = storage_path('logs');

        if (File::exists($logsPath)) {
            try {
                $files = File::glob($logsPath.'/*.log');
                $deletedCount = 0;
                $retentionDays = (int) config('logging.channels.daily.days', 14);
                $cutoff = now()->subDays($retentionDays);

                foreach ($files as $file) {
                    $fileTime = File::lastModified($file);
                    if ($fileTime < $cutoff->timestamp) {
                        File::delete($file);
                        $deletedCount++;
                    }
                }

                if (! $quick) {
                    $this->line("✓ {$retentionDays}天前的日志文件已清除 (删除 $deletedCount 个文件)");

                    $remainingCount = count(File::glob($logsPath.'/*.log'));
                    $this->line("当前剩余日志文件: $remainingCount 个");
                }
            } catch (Exception $e) {
                if (! $quick) {
                    $this->error('✗ 日志文件清除失败: '.$e->getMessage());
                }
            }
        }
    }

    /**
     * 清除Composer autoload缓存
     */
    private function clearComposerCache(bool $quick): void
    {
        if (! $quick) {
            $this->newLine();
            $this->info('5. 清除Composer autoload缓存');
            $this->info('============================================');
        }

        try {
            $composerPath = base_path();
            $process = new Process(
                ['composer', 'dump-autoload', '--optimize'],
                $composerPath
            );

            $process->run();

            if ($process->isSuccessful()) {
                if (! $quick) {
                    $this->line('✓ Composer autoload缓存清除成功');
                }
            } else {
                if (! $quick) {
                    $this->error('✗ Composer autoload缓存清除失败');
                    $this->error($process->getErrorOutput());
                }
            }
        } catch (Exception) {
            if (! $quick) {
                $this->error('✗ Composer命令不可用，跳过autoload缓存清除');
            }
        }
    }

    /**
     * 清除 OPcache 字节码缓存
     *
     * 只有跑在 PHP-FPM 里（后台「清除缓存」按钮走 Artisan::call，与 worker 同进程）
     * 才真能清掉线上生效的字节码；命令行进程清的是自己的 OPcache，够不到 FPM。
     * 后一种情况必须明说，不能报「清除成功」——那是假成功信号。
     */
    private function clearOpcache(bool $quick): void
    {
        if (! $quick) {
            $this->newLine();
            $this->info('6. 清除 OPcache 字节码缓存');
            $this->info('============================================');
        }

        $opcache = app(Opcache::class);
        $result = $opcache->reset();

        if ($result['status'] === Opcache::OK) {
            if ($opcache->isCli()) {
                // quick 模式也提示：否则命令行用户会以为线上字节码已经换掉
                $this->warn('⚠ 已清除当前命令行进程的 OPcache；PHP-FPM 的字节码缓存不受影响，需重载 PHP-FPM 才生效');
            } elseif (! $quick) {
                $this->line('✓ OPcache 字节码缓存清除成功');
            }

            return;
        }

        if ($result['status'] === Opcache::FAILED) {
            $this->error($result['reason'] === 'reset_threw'
                ? '✗ OPcache 清除失败：'.($result['message'] ?? 'opcache_reset() 抛出异常')
                : '✗ OPcache 清除失败：opcache_reset() 返回 false');
            $opcache->reportFailure($result, 'artisan cache:clear-all');

            return;
        }

        if ($result['reason'] === 'api_restricted') {
            $this->warn('⚠ 跳过 OPcache 清理：API 被 opcache.restrict_api 限制'.
                ($result['message'] !== null ? "（{$result['message']}）" : ''));
            $opcache->reportFailure($result, 'artisan cache:clear-all');

            return;
        }

        if (! $quick) {
            $reason = match ($result['reason']) {
                'extension_not_loaded' => '未加载 OPcache 扩展',
                'not_enabled' => "当前 SAPI（{$result['sapi']}）未启用 OPcache，无缓存可清",
                default => (string) $result['reason'],
            };
            $this->line("- 跳过 OPcache 清理：$reason");
        }
    }

    /**
     * 重启队列服务
     */
    private function restartQueueService(bool $quick): void
    {
        if (! $quick) {
            $this->newLine();
            $this->info('7. 重启队列服务');
            $this->info('============================================');
        }

        try {
            // 使用Laravel Artisan命令重启队列
            Artisan::call('queue:restart');

            if (! $quick) {
                $this->line('✓ 队列工作进程已发送重启信号');
                $this->line('✓ 所有队列工作进程将在当前任务完成后重启');
            }
        } catch (Exception $e) {
            if (! $quick) {
                $this->error('✗ 队列服务重启失败: '.$e->getMessage());
                $this->warn('提示：可以手动执行 php artisan queue:restart');
            }
        }

        // 如果使用 Supervisor 管理队列，提示确认重启
        if (! $quick) {
            $this->line('注意：如果使用Supervisor管理队列，请确认队列进程已重启');
        }
    }

    /**
     * 显示缓存状态
     */
    private function showCacheStatus(): void
    {
        $this->info('当前缓存目录状态:');

        $paths = [
            'bootstrap/cache' => 'Bootstrap缓存',
            'storage/framework/cache' => 'Storage框架缓存',
            'storage/logs' => '日志文件',
        ];

        foreach ($paths as $path => $description) {
            $fullPath = base_path($path);
            if (File::exists($fullPath)) {
                $size = $this->getDirectorySize($fullPath);
                $this->line("  $description: $size");
            }
        }
    }

    /**
     * 显示建议操作
     */
    private function showRecommendations(bool $restartQueue): void
    {
        $this->newLine();
        $this->info('建议接下来执行以下操作:');
        $this->line('  php artisan config:cache    # 重新生成配置缓存');
        $this->line('  php artisan route:cache     # 重新生成路由缓存');

        if ($restartQueue) {
            $this->line('  队列服务已重启，无需重复重启');
            $this->line('  重启Web服务器（如nginx/apache）');
        } else {
            $this->line('  重启Web服务器和队列进程');
            $this->line('  php artisan queue:restart   # 重启队列服务');
        }

        $this->newLine();
        $this->info('常用缓存清理命令：');
        $this->line('  php artisan cache:clear-all --quick --restart-queue  # 快速清理+重启队列');
        $this->line('  php artisan cache:clear-all --logs                   # 清理缓存+旧日志');
    }

    /**
     * 获取目录大小
     */
    private function getDirectorySize(string $path): string
    {
        try {
            $size = 0;
            $files = File::allFiles($path);

            foreach ($files as $file) {
                $size += $file->getSize();
            }

            return $this->formatBytes($size);
        } catch (Exception) {
            return '无法计算';
        }
    }

    /**
     * 格式化字节大小
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }
}
