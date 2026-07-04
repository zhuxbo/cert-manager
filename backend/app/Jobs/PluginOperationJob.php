<?php

namespace App\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\PluginOperation;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PluginOperationJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $operationUuid)
    {
        $this->timeout = (int) config('plugin.operations.timeout', 270);
    }

    public function handle(PluginOperationService $operations, PluginManager $manager): void
    {
        $operation = PluginOperation::where('uuid', $this->operationUuid)->first();
        if (! $operation || $operation->isTerminal()) {
            return;
        }

        if ($operation->status === PluginOperation::STATUS_RUNNING) {
            $this->handleAlreadyRunning($operations, $operation);

            return;
        }

        try {
            $operations->withPluginMutex($operation->plugin_name, function () use ($operations, $manager, $operation) {
                $runToken = $operations->beginRunning($operation);
                if ($runToken === null) {
                    return;
                }

                $reporter = function (string $stage, string $message) use ($operations, $operation, $runToken): void {
                    $operations->heartbeat($operation, $runToken, $stage, $message);
                };

                try {
                    $result = $this->executeOperation($manager->withProgressReporter($reporter), $operations, $operation);
                    $operations->markSucceeded($operation, $runToken, $result);
                } catch (Throwable $e) {
                    $message = $operations->sanitizeError($e->getMessage());
                    Log::error('[Plugin] operation failed', [
                        'operation_uuid' => $operation->uuid,
                        'plugin_name' => $operation->plugin_name,
                        'error' => $message,
                    ]);
                    $operations->markFailed($operation, $runToken, $e);
                }
            });
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '已有操作正在执行')) {
                $this->release(30);

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $operation = PluginOperation::where('uuid', $this->operationUuid)->first();
        if (! $operation || $operation->isTerminal()) {
            return;
        }

        $operations = app(PluginOperationService::class);
        if ($operation->run_token) {
            $operations->markFailed($operation, $operation->run_token, $e);

            return;
        }

        if ($operation->status === PluginOperation::STATUS_QUEUED) {
            $operations->markQueuedFailed($operation, $e);
        }
    }

    private function executeOperation(PluginManager $manager, PluginOperationService $operations, PluginOperation $operation): array
    {
        return match ($operation->type) {
            PluginOperation::TYPE_INSTALL_REMOTE => $manager->install(
                $operation->plugin_name,
                $operation->release_url,
                $operation->version,
            ),
            PluginOperation::TYPE_INSTALL_UPLOAD => $manager->installFromZip($operations->uploadFullPath($operation)),
            PluginOperation::TYPE_UPDATE => $manager->update($operation->plugin_name, $operation->version),
            default => throw new \RuntimeException("未知插件操作类型: $operation->type"),
        };
    }

    private function handleAlreadyRunning(PluginOperationService $operations, PluginOperation $operation): void
    {
        if (! $operation->isStale()) {
            $this->release(30);

            return;
        }

        if ($operations->lockExists($operation->plugin_name)) {
            $this->release(30);

            return;
        }

        $operations->failStale($operation->uuid);
    }
}
