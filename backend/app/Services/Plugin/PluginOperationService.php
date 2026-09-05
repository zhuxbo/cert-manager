<?php

namespace App\Services\Plugin;

use App\Models\PluginOperation;
use App\Support\RuntimeCache;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PluginOperationService
{
    private const LOCK_PREFIX = 'plugin_operation:mutex:';

    private const RETRYABLE_TYPES = [
        PluginOperation::TYPE_INSTALL_REMOTE,
        PluginOperation::TYPE_UPDATE,
    ];

    public function __construct(
        private PluginZipInspector $zipInspector,
    ) {}

    public function createRemoteInstall(int $adminId, string $name, ?string $releaseUrl, ?string $version): PluginOperation
    {
        $this->assertConfigSafe();
        $this->validatePluginName($name);
        $this->cleanupFinishedUploads();

        return $this->withPluginMutex($name, function () use ($adminId, $name, $releaseUrl, $version) {
            return DB::transaction(function () use ($adminId, $name, $releaseUrl, $version) {
                $this->assertNoBlockingOperation($name);

                return PluginOperation::create([
                    'uuid' => (string) Str::uuid(),
                    'type' => PluginOperation::TYPE_INSTALL_REMOTE,
                    'plugin_name' => $name,
                    'version' => $version,
                    'release_url' => $releaseUrl,
                    'status' => PluginOperation::STATUS_QUEUED,
                    'stage' => PluginOperation::STAGE_QUEUED,
                    'message' => '插件安装任务已创建',
                    'admin_id' => $adminId,
                ]);
            });
        });
    }

    public function createUploadInstall(int $adminId, UploadedFile $file): PluginOperation
    {
        $this->assertConfigSafe();
        $this->cleanupFinishedUploads();

        if ($file->getSize() > 100 * 1024 * 1024) {
            throw new RuntimeException('文件大小超过限制（最大 100MB）');
        }

        if ($file->getClientOriginalExtension() !== 'zip') {
            throw new RuntimeException('仅支持 ZIP 格式');
        }

        $tmpPath = $file->store('', ['disk' => 'local']);
        $fullPath = storage_path("app/$tmpPath");
        $movedUploadPath = null;

        try {
            $meta = $this->zipInspector->inspect($fullPath);
            $name = $meta['name'];
            $this->validatePluginName($name);

            return $this->withPluginMutex($name, function () use ($adminId, $name, $meta, $fullPath, &$movedUploadPath) {
                return DB::transaction(function () use ($adminId, $name, $meta, $fullPath, &$movedUploadPath) {
                    $this->assertNoBlockingOperation($name);

                    $uuid = (string) Str::uuid();
                    $dir = storage_path("app/plugin-operations/$uuid");
                    File::makeDirectory($dir, 0755, true);
                    $uploadPath = "plugin-operations/$uuid/source.zip";
                    File::move($fullPath, storage_path("app/$uploadPath"));
                    $movedUploadPath = $uploadPath;

                    return PluginOperation::create([
                        'uuid' => $uuid,
                        'type' => PluginOperation::TYPE_INSTALL_UPLOAD,
                        'plugin_name' => $name,
                        'version' => $meta['version'],
                        'upload_path' => $uploadPath,
                        'status' => PluginOperation::STATUS_QUEUED,
                        'stage' => PluginOperation::STAGE_QUEUED,
                        'message' => '插件安装任务已创建',
                        'admin_id' => $adminId,
                    ]);
                });
            });
        } catch (Throwable $e) {
            if ($movedUploadPath !== null) {
                $this->cleanupUploadPath($movedUploadPath);
            }

            throw $e;
        } finally {
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    public function createUpdate(int $adminId, string $name, ?string $version): PluginOperation
    {
        $this->assertConfigSafe();
        $this->validatePluginName($name);
        $this->cleanupFinishedUploads();

        return $this->withPluginMutex($name, function () use ($adminId, $name, $version) {
            return DB::transaction(function () use ($adminId, $name, $version) {
                $this->assertNoBlockingOperation($name);

                return PluginOperation::create([
                    'uuid' => (string) Str::uuid(),
                    'type' => PluginOperation::TYPE_UPDATE,
                    'plugin_name' => $name,
                    'version' => $version,
                    'status' => PluginOperation::STATUS_QUEUED,
                    'stage' => PluginOperation::STAGE_QUEUED,
                    'message' => '插件更新任务已创建',
                    'admin_id' => $adminId,
                ]);
            });
        });
    }

    public function listVisible(): array
    {
        $this->cleanupFinishedUploads();

        return PluginOperation::query()
            ->whereIn('status', [
                PluginOperation::STATUS_QUEUED,
                PluginOperation::STATUS_RUNNING,
                PluginOperation::STATUS_FAILED,
            ])
            ->orWhere('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PluginOperation $operation) => $operation->toPublicArray())
            ->all();
    }

    public function findVisible(string $uuid): PluginOperation
    {
        return PluginOperation::where('uuid', $uuid)->firstOrFail();
    }

    public function toPublicArray(PluginOperation $operation): array
    {
        return $operation->toPublicArray();
    }

    public function assertNoActiveOperation(string $pluginName): void
    {
        $active = PluginOperation::where('plugin_name', $pluginName)
            ->whereIn('status', [PluginOperation::STATUS_QUEUED, PluginOperation::STATUS_RUNNING])
            ->exists();

        if ($active) {
            throw new RuntimeException("插件 $pluginName 已有安装或更新任务正在执行，请稍后再试");
        }
    }

    public function assertNoBlockingOperation(string $pluginName): void
    {
        $blocking = PluginOperation::where('plugin_name', $pluginName)
            ->whereIn('status', [
                PluginOperation::STATUS_QUEUED,
                PluginOperation::STATUS_RUNNING,
                PluginOperation::STATUS_FAILED,
            ])
            ->latest('id')
            ->first();

        if (! $blocking) {
            return;
        }

        if ($blocking->status === PluginOperation::STATUS_FAILED) {
            $action = $blocking->type === PluginOperation::TYPE_UPDATE
                ? '重试或取消'
                : '重试或卸载';

            throw new RuntimeException("插件 $pluginName 上次安装或更新失败，请先{$action}");
        }

        throw new RuntimeException("插件 $pluginName 已有安装或更新任务正在执行，请稍后再试");
    }

    public function retryFailed(PluginOperation $operation): PluginOperation
    {
        if ($operation->status !== PluginOperation::STATUS_FAILED) {
            throw new RuntimeException('只能重试失败的插件任务');
        }

        if (! in_array($operation->type, self::RETRYABLE_TYPES, true)) {
            throw new RuntimeException('上传安装失败记录不能直接重试，请重新上传 ZIP 文件');
        }

        $this->assertConfigSafe();

        return $this->withPluginMutex($operation->plugin_name, function () use ($operation) {
            return DB::transaction(function () use ($operation) {
                $current = PluginOperation::whereKey($operation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($current->status !== PluginOperation::STATUS_FAILED) {
                    throw new RuntimeException('只能重试失败的插件任务');
                }

                $this->assertNoActiveOperation($current->plugin_name);

                $current->forceFill([
                    'status' => PluginOperation::STATUS_QUEUED,
                    'stage' => PluginOperation::STAGE_QUEUED,
                    'message' => '插件任务已重新加入队列',
                    'error' => null,
                    'result' => null,
                    'run_token' => null,
                    'last_heartbeat_at' => null,
                    'started_at' => null,
                    'finished_at' => null,
                ])->save();

                return $current->refresh();
            });
        });
    }

    public function clearFailedInstallsForPlugin(string $pluginName): int
    {
        return PluginOperation::where('plugin_name', $pluginName)
            ->where('status', PluginOperation::STATUS_FAILED)
            ->whereIn('type', [
                PluginOperation::TYPE_INSTALL_REMOTE,
                PluginOperation::TYPE_INSTALL_UPLOAD,
            ])
            ->delete();
    }

    public function cancelFailedUpdate(PluginOperation $operation): void
    {
        if ($operation->status !== PluginOperation::STATUS_FAILED
            || $operation->type !== PluginOperation::TYPE_UPDATE) {
            throw new RuntimeException('只能取消失败的更新任务');
        }

        $this->withPluginMutex($operation->plugin_name, function () use ($operation) {
            DB::transaction(function () use ($operation) {
                $current = PluginOperation::whereKey($operation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($current->status !== PluginOperation::STATUS_FAILED
                    || $current->type !== PluginOperation::TYPE_UPDATE) {
                    throw new RuntimeException('只能取消失败的更新任务');
                }

                $this->assertNoActiveOperation($current->plugin_name);
                $current->delete();
            });
        });
    }

    public function beginRunning(PluginOperation $operation): ?string
    {
        $runToken = bin2hex(random_bytes(16));
        $updated = PluginOperation::whereKey($operation->id)
            ->where('status', PluginOperation::STATUS_QUEUED)
            ->update([
                'status' => PluginOperation::STATUS_RUNNING,
                'stage' => 'starting',
                'message' => '插件任务开始执行',
                'run_token' => $runToken,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        return $updated === 1 ? $runToken : null;
    }

    public function heartbeat(PluginOperation $operation, string $runToken, string $stage, string $message): void
    {
        PluginOperation::whereKey($operation->id)
            ->where('status', PluginOperation::STATUS_RUNNING)
            ->where('run_token', $runToken)
            ->update([
                'stage' => $stage,
                'message' => $message,
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markSucceeded(PluginOperation $operation, string $runToken, array $result): void
    {
        PluginOperation::whereKey($operation->id)
            ->where('status', PluginOperation::STATUS_RUNNING)
            ->where('run_token', $runToken)
            ->update([
                'status' => PluginOperation::STATUS_SUCCEEDED,
                'stage' => 'done',
                'message' => (string) ($result['message'] ?? '插件任务已完成'),
                'result' => json_encode($this->sanitizeResult($result), JSON_UNESCAPED_UNICODE),
                'error' => null,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        $operation->refresh();
        $this->cleanupUpload($operation);
    }

    public function markFailed(PluginOperation $operation, string $runToken, Throwable|string $error): void
    {
        $message = $this->sanitizeError($error instanceof Throwable ? $error->getMessage() : $error);
        PluginOperation::whereKey($operation->id)
            ->where('status', PluginOperation::STATUS_RUNNING)
            ->where('run_token', $runToken)
            ->update([
                'status' => PluginOperation::STATUS_FAILED,
                'stage' => PluginOperation::STAGE_ERROR,
                'message' => $message,
                'error' => $message,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        $operation->refresh();
        $this->cleanupUpload($operation);
    }

    public function markQueuedFailed(PluginOperation $operation, Throwable|string $error): void
    {
        $message = $this->sanitizeError($error instanceof Throwable ? $error->getMessage() : $error);
        PluginOperation::whereKey($operation->id)
            ->where('status', PluginOperation::STATUS_QUEUED)
            ->whereNull('run_token')
            ->update([
                'status' => PluginOperation::STATUS_FAILED,
                'stage' => PluginOperation::STAGE_ERROR,
                'message' => $message,
                'error' => $message,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $operation->refresh();
        $this->cleanupUpload($operation);
    }

    public function failStale(string $uuid): PluginOperation
    {
        $operation = PluginOperation::where('uuid', $uuid)->firstOrFail();
        if (! $operation->isStale()) {
            throw new RuntimeException('插件任务尚未超时，不能标记失败');
        }

        $lock = RuntimeCache::lock($this->lockKey($operation->plugin_name), 1);
        if (! $lock->get()) {
            throw new RuntimeException('插件任务仍可能在执行，请稍后再试');
        }

        try {
            $query = PluginOperation::whereKey($operation->id)
                ->where('status', $operation->status);

            if ($operation->status === PluginOperation::STATUS_RUNNING) {
                $query->where('run_token', $operation->run_token);
            }

            $updated = $query->update([
                'status' => PluginOperation::STATUS_FAILED,
                'stage' => PluginOperation::STAGE_ERROR,
                'message' => '上次插件任务未完成，请检查插件状态后重试或卸载',
                'error' => '上次插件任务未完成，请检查插件状态后重试或卸载',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($updated !== 1) {
                throw new RuntimeException('插件任务状态已变化，请刷新后重试');
            }
        } finally {
            $lock->release();
        }

        $operation->refresh();
        $this->cleanupUpload($operation);

        return $operation;
    }

    public function withPluginMutex(string $pluginName, Closure $callback, ?int $ttl = null): mixed
    {
        $lock = RuntimeCache::lock($this->lockKey($pluginName), $ttl ?? $this->lockTtl());
        if (! $lock->get()) {
            throw new RuntimeException("插件 $pluginName 已有操作正在执行，请稍后再试");
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function lockExists(string $pluginName): bool
    {
        $lock = RuntimeCache::lock($this->lockKey($pluginName), 1);
        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    public function uploadFullPath(PluginOperation $operation): string
    {
        if (! $operation->upload_path) {
            throw new RuntimeException('插件上传包不存在');
        }

        return storage_path("app/$operation->upload_path");
    }

    public function assertConfigSafe(): void
    {
        $timeout = (int) config('plugin.operations.timeout', 720);
        $margin = (int) config('plugin.operations.timeout_margin', 60);
        $download = (int) config('plugin.download.timeout', 120);
        $composer = (int) config('plugin.composer.timeout', 210);
        $overhead = (int) config('plugin.operations.overhead_margin', 30);
        $artisan = (int) config('plugin.operations.artisan_timeout', 60);
        $retryAfter = $this->queueRetryAfter();

        if ($retryAfter > 0 && $retryAfter <= $timeout + $margin) {
            throw new RuntimeException('插件安装任务超时配置必须小于队列 retry_after，请调整 QUEUE_RETRY_AFTER 或 PLUGIN_OPERATION_TIMEOUT 后重试');
        }

        // curl 失败后 HTTP fallback 仍拥有完整单次超时，任务预算必须覆盖两次尝试。
        if (($download * 2) + $composer + $overhead + ($artisan * 3) > $timeout) {
            throw new RuntimeException('插件安装任务 timeout 预算不足，请调整 PLUGIN_OPERATION_TIMEOUT / PLUGIN_DOWNLOAD_TIMEOUT / PLUGIN_COMPOSER_TIMEOUT / PLUGIN_OPERATION_ARTISAN_TIMEOUT');
        }
    }

    private function queueRetryAfter(): int
    {
        $connection = config('queue.default');

        return (int) config("queue.connections.$connection.retry_after", 0);
    }

    private function lockTtl(): int
    {
        return (int) config('plugin.operations.timeout', 720)
            + (int) config('plugin.operations.timeout_margin', 60);
    }

    private function lockKey(string $pluginName): string
    {
        return self::LOCK_PREFIX.$pluginName;
    }

    private function validatePluginName(string $name): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new RuntimeException("无效的插件名: {$name}（仅允许小写字母、数字和连字符，以字母开头）");
        }
    }

    private function sanitizeResult(array $result): array
    {
        return array_intersect_key($result, array_flip([
            'name',
            'version',
            'from_version',
            'message',
            'nginx_reload',
            'remove_data',
        ]));
    }

    public function sanitizeError(string $message): string
    {
        $message = PluginOutputSanitizer::sanitize($message, 1000);
        $message = preg_replace('#/[^\\s]+#', '[path]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }

    private function cleanupUpload(PluginOperation $operation): void
    {
        if (! $operation->upload_path) {
            return;
        }

        $this->cleanupUploadPath($operation->upload_path);
    }

    private function cleanupUploadPath(string $uploadPath): void
    {
        $path = storage_path("app/$uploadPath");
        if (is_file($path)) {
            @unlink($path);
        }

        $dir = dirname($path);
        if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
    }

    private function cleanupFinishedUploads(): void
    {
        $cutoff = now()->subSeconds((int) config('plugin.operations.cleanup_finished_after', 86400));
        PluginOperation::whereIn('status', [PluginOperation::STATUS_SUCCEEDED, PluginOperation::STATUS_FAILED])
            ->whereNotNull('upload_path')
            ->where('finished_at', '<', $cutoff)
            ->get()
            ->each(fn (PluginOperation $operation) => $this->cleanupUpload($operation));
    }
}
