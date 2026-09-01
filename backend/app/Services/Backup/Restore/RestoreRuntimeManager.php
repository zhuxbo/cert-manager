<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use App\Utils\UpgradeFreezeLock;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

final class RestoreRuntimeManager
{
    private const CLEANUP_RETRY_MESSAGE = '数据库已验证、运行时清理待重试';

    public function freeze(string $reason): void
    {
        [$connection, $queues] = $this->queueTargets();
        if (! UpgradeFreezeLock::freezeRestore($reason)) {
            throw new RuntimeException('无法创建数据库恢复冻结锁');
        }

        try {
            foreach ($queues as $queue) {
                Queue::pause($connection, $queue);
            }
            if (Artisan::call('down', ['--retry' => 60]) !== 0) {
                throw new RuntimeException('无法进入维护模式');
            }
            $this->assertStillFrozen();
        } catch (Throwable $failure) {
            $this->rollbackFailedFreeze($reason, $connection, $queues, $failure);
        }
    }

    public function clearRuntimeState(callable $republishProgress): void
    {
        try {
            $this->assertStillFrozen();
            [$connection, $queues] = $this->queueTargets();
            $queue = Queue::connection($connection);
            if (! $queue instanceof ClearableQueue) {
                throw new RuntimeException('当前队列驱动不支持安全清空');
            }
            foreach ($queues as $name) {
                $queue->clear($name);
            }

            if (! Cache::flush()) {
                throw new RuntimeException('应用缓存清理失败');
            }

            $this->pauseQueues();
            $republishProgress();
            $this->assertStillFrozen();
        } catch (Throwable $e) {
            $this->keepFailClosed(self::CLEANUP_RETRY_MESSAGE);

            throw new RuntimeException(self::CLEANUP_RETRY_MESSAGE, 0, $e);
        }
    }

    public function resume(): void
    {
        $this->assertStillFrozen();
        $reason = (string) (UpgradeFreezeLock::info()['reason'] ?? 'database restore');

        try {
            if (! UpgradeFreezeLock::unfreeze('restore')) {
                throw new RuntimeException('无法解除数据库恢复冻结锁');
            }
            if (Artisan::call('up') !== 0) {
                throw new RuntimeException('无法退出维护模式');
            }
            [$connection, $queues] = $this->queueTargets();
            foreach ($queues as $queue) {
                Queue::resume($connection, $queue);
            }
        } catch (Throwable $e) {
            $this->keepFailClosed($reason);

            throw new RuntimeException('数据库恢复退出失败，系统保持冻结', 0, $e);
        }
    }

    public function assertStillFrozen(): void
    {
        $lock = UpgradeFreezeLock::info();
        if (($lock['owner_source'] ?? null) !== 'restore' || ! app()->isDownForMaintenance()) {
            throw new RuntimeException('恢复运行时冻结状态不完整');
        }

        [$connection, $queues] = $this->queueTargets();
        foreach ($queues as $queue) {
            if (! Queue::isPaused($connection, $queue)) {
                throw new RuntimeException('恢复运行时冻结状态不完整');
            }
        }
    }

    private function pauseQueues(): void
    {
        [$connection, $queues] = $this->queueTargets();
        foreach ($queues as $queue) {
            Queue::pause($connection, $queue);
        }
    }

    private function keepFailClosed(string $reason): void
    {
        $lock = UpgradeFreezeLock::info();
        if (($lock['owner_source'] ?? null) !== 'restore') {
            UpgradeFreezeLock::freezeRestore($reason);
        }

        try {
            $this->pauseQueues();
        } catch (Throwable) {
        }

        try {
            Artisan::call('down', ['--retry' => 60]);
        } catch (Throwable) {
        }
    }

    /** @param list<string> $queues */
    private function rollbackFailedFreeze(
        string $reason,
        string $connection,
        array $queues,
        Throwable $failure,
    ): never {
        $rollbackFailure = null;

        try {
            if (! UpgradeFreezeLock::unfreeze('restore')) {
                throw new RuntimeException('无法解除数据库恢复冻结锁');
            }
        } catch (Throwable $e) {
            $rollbackFailure = $e;
        }

        try {
            if (Artisan::call('up') !== 0) {
                throw new RuntimeException('无法退出维护模式');
            }
        } catch (Throwable $e) {
            $rollbackFailure ??= $e;
        }

        foreach ($queues as $queue) {
            try {
                Queue::resume($connection, $queue);
            } catch (Throwable $e) {
                $rollbackFailure ??= $e;
            }
        }

        try {
            if (UpgradeFreezeLock::isFrozen() || app()->isDownForMaintenance()) {
                throw new RuntimeException('数据库恢复冻结回退状态不完整');
            }
            foreach ($queues as $queue) {
                if (Queue::isPaused($connection, $queue)) {
                    throw new RuntimeException('数据库恢复冻结回退状态不完整');
                }
            }
        } catch (Throwable $e) {
            $rollbackFailure ??= $e;
        }

        if ($rollbackFailure === null) {
            throw new RuntimeException('数据库恢复冻结失败，已恢复运行状态', 0, $failure);
        }

        $this->keepFailClosed($reason);
        try {
            $this->assertStillFrozen();
        } catch (Throwable $freezeFailure) {
            throw new RuntimeException(
                '数据库恢复冻结失败，且无法恢复完整运行或冻结状态: '.$freezeFailure->getMessage(),
                0,
                $failure,
            );
        }

        throw new RuntimeException(
            '数据库恢复冻结失败且无法回退，系统保持冻结: '.$rollbackFailure->getMessage(),
            0,
            $failure,
        );
    }

    /** @return array{string, list<string>} */
    private function queueTargets(): array
    {
        $connection = config('queue.default');
        if (! is_string($connection) || $connection === '' || ! is_array(config("queue.connections.{$connection}"))) {
            throw new RuntimeException('默认队列连接配置无效');
        }

        $configured = config('queue.names', []);
        $queues = is_array($configured) ? array_values($configured) : [];
        $queues[] = config("queue.connections.{$connection}.queue", 'default');
        $queues = array_values(array_unique(array_filter(
            $queues,
            fn (mixed $queue): bool => is_string($queue) && $queue !== '',
        )));
        if ($queues === []) {
            throw new RuntimeException('恢复队列名称配置无效');
        }

        return [$connection, $queues];
    }
}
