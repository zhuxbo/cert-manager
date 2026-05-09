<?php

namespace App\Http\Controllers\Admin;

use App\Services\Upgrade\SmokeChecker;
use App\Services\Upgrade\UpgradeService;
use App\Services\Upgrade\UpgradeStatusManager;
use App\Services\Upgrade\VersionManager;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UpgradeController extends BaseController
{
    public function __construct(
        protected VersionManager $versionManager,
        protected UpgradeService $upgradeService,
        protected UpgradeStatusManager $statusManager,
    ) {
        parent::__construct();
    }

    /**
     * 获取当前版本信息
     */
    public function version(): void
    {
        $version = $this->versionManager->getCurrentVersion();

        $this->success($version);
    }

    /**
     * 检查更新
     */
    public function check(): void
    {
        $result = $this->upgradeService->checkForUpdate();

        $this->success($result);
    }

    /**
     * 获取历史版本列表
     */
    public function releases(Request $request): void
    {
        $limit = $request->input('limit', 5);
        $releases = $this->upgradeService->getReleaseHistory($limit);

        $this->success([
            'releases' => $releases,
            'current_version' => $this->versionManager->getVersionString(),
        ]);
    }

    /**
     * 启动升级任务（后台执行）
     */
    public function execute(Request $request): void
    {
        $version = $request->input('version', 'latest');

        // 检查是否已有升级任务在运行
        if ($this->statusManager->isRunning()) {
            $this->error('已有升级任务在运行中');
        }

        // 清理旧的状态文件
        $this->statusManager->clear();

        // 启动后台升级进程，输出重定向到日志文件
        $phpBinary = $this->findPhpBinary();
        $artisan = base_path('artisan');
        $logFile = storage_path('logs/upgrade-process.log');

        $command = sprintf(
            '%s %s upgrade:run %s >> %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            escapeshellarg($version),
            escapeshellarg($logFile)
        );

        Log::info("[Upgrade] 启动升级任务: $version", [
            'command' => $command,
            'log_file' => $logFile,
        ]);

        exec($command);

        // 等待并检查进程是否成功启动
        // 多次重试，最多等待 3 秒
        $maxRetries = 6;
        $status = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            usleep(500000); // 0.5秒

            $status = $this->statusManager->get();
            if ($status) {
                break;
            }
        }

        // 检查状态文件是否已创建
        if (! $status) {
            Log::error('[Upgrade] 状态文件未创建，进程启动失败', [
                'command' => $command,
                'log_file' => $logFile,
            ]);

            // 检查日志文件是否有错误信息
            $errorMessage = '升级进程启动失败';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (! empty($logContent)) {
                    // 获取最后几行日志
                    $lines = array_slice(explode("\n", trim($logContent)), -5);
                    $errorMessage .= '：'.implode(' ', $lines);
                }
            }

            $this->error($errorMessage);
        }

        $this->success([
            'started' => true,
            'version' => $version,
            'message' => '升级任务已启动，请轮询状态接口获取进度',
        ]);
    }

    /**
     * 获取升级状态
     */
    public function status(): void
    {
        $status = $this->statusManager->get();

        if (! $status) {
            $this->success([
                'status' => 'idle',
                'message' => '没有进行中的升级任务',
            ]);
        }

        $this->success($status);
    }

    /**
     * 获取备份列表
     */
    public function backups(): void
    {
        $backups = $this->upgradeService->getBackups();

        $this->success([
            'backups' => $backups,
        ]);
    }

    /**
     * 执行回滚
     */
    public function rollback(Request $request): void
    {
        $backupId = $request->input('backup_id');

        if (! $backupId) {
            $this->error('请指定要恢复的备份');
        }

        $result = $this->upgradeService->rollback($backupId);

        if ($result['success']) {
            $this->success($result);
        } else {
            $this->error($result['error'] ?? '回滚失败');
        }
    }

    /**
     * 删除备份
     */
    public function deleteBackup(Request $request): void
    {
        $backupId = $request->input('backup_id');

        if (! $backupId) {
            $this->error('请指定要删除的备份');
        }

        $deleted = $this->upgradeService->deleteBackup($backupId);

        if ($deleted) {
            $this->success(['deleted' => true]);
        } else {
            $this->error('删除备份失败');
        }
    }

    /**
     * 设置发布通道
     */
    public function setChannel(Request $request): void
    {
        $channel = $request->input('channel');

        if (! in_array($channel, ['main', 'dev'])) {
            $this->error("无效的通道: $channel");
        }

        $result = $this->versionManager->setChannel($channel);

        if ($result) {
            $this->success([
                'channel' => $channel,
                'message' => '通道已切换',
            ]);
        } else {
            $this->error('切换通道失败');
        }
    }

    /**
     * 写入升级冻结锁（HTTP 维护态生效）
     *
     * 由升级流程在切代码 / 跑 migrate 之前调用：
     * 1. 写文件 storage/framework/upgrade.lock
     * 2. MaintenanceMode 中间件下次命中即返回 503（白名单除外）
     * 3. LogOperation 短路不再写日志
     *
     * 入参均可选，调用方一般传 version_from / version_to / ttl_seconds 三项。
     */
    public function freeze(Request $request): void
    {
        $request->validate([
            'version_from' => 'nullable|string|max:64',
            'version_to' => 'nullable|string|max:64',
            'ttl_seconds' => 'nullable|integer|min:60|max:7200',
        ]);

        $ok = UpgradeFreezeLock::freeze(
            $request->input('version_from'),
            $request->input('version_to'),
            (int) $request->input('ttl_seconds', 7200),
        );

        if (! $ok) {
            // 写锁失败（磁盘 / 权限 / json_encode）—— 必须返回错误，避免升级流程误判 freeze 已激活
            // ApiResponse::error 仅接收 (msg, errors) 二参；HTTP 状态由 ExceptionHandler 通过 code=0 的约定渲染
            $this->error('写入升级锁失败，请检查 storage/framework 目录权限和磁盘空间');
        }

        $this->success(UpgradeFreezeLock::info());
    }

    /**
     * 删除升级冻结锁（HTTP 维护态解除）
     *
     * 升级流程在新版本 smoke test 通过后调用：
     * 1. 删除 storage/framework/upgrade.lock
     * 2. MaintenanceMode 中间件下次命中即放行
     * 3. LogOperation 恢复正常写日志
     */
    public function unfreeze(): void
    {
        UpgradeFreezeLock::unfreeze();

        $this->success();
    }

    /**
     * opcache 重置
     *
     * 升级期切代码后由管理员调用：
     * 1. 临时 ini_set('opcache.validate_timestamps', '1')，让 fpm worker 命中文件 mtime 重编
     * 2. 调 opcache_reset() 清 SHM 缓存
     *
     * opcache 扩展未加载时返回 status=skipped；调用方据此决定是否走宝塔 php_reload 兜底。
     */
    public function opcacheReset(): void
    {
        if (! function_exists('opcache_reset')) {
            $this->success([
                'status' => 'skipped',
                'reason' => 'opcache_extension_not_loaded',
            ]);
        } else {
            @ini_set('opcache.validate_timestamps', '1');
            $ok = opcache_reset();

            $opcacheStatus = null;
            if (function_exists('opcache_get_status')) {
                // false 参数省略 scripts，避免大数组返回
                $status = opcache_get_status(false);
                if (is_array($status)) {
                    $opcacheStatus = array_intersect_key(
                        $status,
                        array_flip(['opcache_enabled', 'cache_full'])
                    );
                }
            }

            $this->success([
                'status' => $ok ? 'ok' : 'failed',
                'opcache_status' => $opcacheStatus,
            ]);
        }
    }

    /**
     * 升级 freeze 内部 smoke test
     *
     * 由 upgrade.sh / 后台覆盖式升级流程在切完代码 / migrate 后、unfreeze 前调用：
     * - DB ping
     * - jobs 表可读
     * - 关键路由已注册
     *
     * 任一 check 失败返回 503 + {status: failed, checks}，调用方据此决定回滚链路。
     */
    public function smoke(SmokeChecker $checker): JsonResponse
    {
        $result = $checker->run();

        $payload = [
            'code' => $result['ok'] ? 1 : 0,
            'data' => [
                'status' => $result['ok'] ? 'ok' : 'failed',
                'checks' => $result['checks'],
            ],
        ];

        if (! $result['ok']) {
            $payload['msg'] = 'smoke test failed';

            return new JsonResponse($payload, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse($payload);
    }

    /**
     * 查找 PHP CLI 二进制路径
     * PHP_BINARY 在 PHP-FPM 环境下返回 php-fpm 路径，需要找到 php CLI
     * 注意：宝塔面板有 open_basedir 限制，需要优先检查允许范围内的路径
     */
    protected function findPhpBinary(): string
    {
        // 如果 PHP_BINARY 不是 php-fpm，直接使用
        if (! str_contains(PHP_BINARY, 'fpm')) {
            return PHP_BINARY;
        }

        // 优先：从 php-fpm 路径推断 php 路径（在宝塔环境下通常在 open_basedir 允许范围内）
        $phpFpmPath = PHP_BINARY;
        $phpPath = str_replace(['php-fpm', 'sbin'], ['php', 'bin'], $phpFpmPath);
        if ($phpPath !== $phpFpmPath && @file_exists($phpPath) && @is_executable($phpPath)) {
            return $phpPath;
        }

        // 尝试常见的 PHP CLI 路径（最低支持 PHP 8.3）
        // 使用 @ 抑制 open_basedir 限制错误
        $candidates = [
            '/www/server/php/84/bin/php',  // 宝塔 PHP 8.4（优先新版本）
            '/www/server/php/83/bin/php',  // 宝塔 PHP 8.3
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/php/bin/php',
        ];

        foreach ($candidates as $path) {
            if (@file_exists($path) && @is_executable($path)) {
                return $path;
            }
        }

        // 尝试从 PATH 中查找
        $output = [];
        @exec('which php 2>/dev/null', $output);
        if (! empty($output[0]) && @file_exists($output[0])) {
            return $output[0];
        }

        // 最后尝试直接使用 'php' 命令
        return 'php';
    }
}
