<?php

declare(strict_types=1);

use App\Services\Backup\Restore\RestoreRuntimeManager;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Queue\Events\QueuePaused;
use Illuminate\Queue\Events\QueueResumed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Jobs\ProbeTriesFiveNoMaxJob;

beforeEach(function () {
    UpgradeFreezeLock::unfreeze('restore');
    Artisan::call('up');
    Cache::flush();

    config([
        'queue.default' => 'restore_database',
        'queue.connections.restore_database' => config('queue.connections.database'),
        'queue.names' => [
            'notifications' => 'priority',
            'tasks' => 'priority',
            'default' => 'default',
        ],
    ]);
});

afterEach(function () {
    foreach (['priority', 'default'] as $queue) {
        Queue::resume('restore_database', $queue);
    }
    UpgradeFreezeLock::unfreeze('restore');
    Artisan::call('up');
    Cache::flush();
});

test('freeze 先写恢复锁并暂停实际连接的去重队列，最后才进入维护模式', function () {
    $events = [];
    Event::listen(QueuePaused::class, function (QueuePaused $event) use (&$events) {
        $events[] = [$event->connection, $event->queue];
        expect(UpgradeFreezeLock::info()['owner_source'] ?? null)->toBe('restore')
            ->and(app()->isDownForMaintenance())->toBeFalse();
    });

    app(RestoreRuntimeManager::class)->freeze('atomic restore');

    expect($events)->toBe([
        ['restore_database', 'priority'],
        ['restore_database', 'default'],
    ])->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'priority'))->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'default'))->toBeTrue();
});

test('freeze 在队列配置错误时不创建锁或进入维护模式', function () {
    config(['queue.default' => 'missing']);

    expect(fn () => app(RestoreRuntimeManager::class)->freeze('atomic restore'))
        ->toThrow(RuntimeException::class, '默认队列连接配置无效');

    expect(UpgradeFreezeLock::info())->toBeNull()
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

test('freeze 暂停队列异常时回滚锁、维护模式和已暂停队列', function () {
    $throwOnce = true;
    Event::listen(QueuePaused::class, function () use (&$throwOnce) {
        if ($throwOnce) {
            $throwOnce = false;
            throw new RuntimeException('pause failed');
        }
    });

    expect(fn () => app(RestoreRuntimeManager::class)->freeze('atomic restore'))
        ->toThrow(RuntimeException::class, '数据库恢复冻结失败，已恢复运行状态');

    expect(UpgradeFreezeLock::info())->toBeNull()
        ->and(app()->isDownForMaintenance())->toBeFalse()
        ->and(Queue::isPaused('restore_database', 'priority'))->toBeFalse()
        ->and(Queue::isPaused('restore_database', 'default'))->toBeFalse();
});

test('freeze 失败回滚队列也异常时重新建立完整冻结', function () {
    $pauseThrows = true;
    $resumeThrows = true;
    Event::listen(QueuePaused::class, function () use (&$pauseThrows) {
        if ($pauseThrows) {
            $pauseThrows = false;
            throw new RuntimeException('pause failed');
        }
    });
    Event::listen(QueueResumed::class, function () use (&$resumeThrows) {
        if ($resumeThrows) {
            $resumeThrows = false;
            throw new RuntimeException('resume failed');
        }
    });

    expect(fn () => app(RestoreRuntimeManager::class)->freeze('atomic restore'))
        ->toThrow(RuntimeException::class, '数据库恢复冻结失败且无法回退，系统保持冻结');

    expect(UpgradeFreezeLock::info()['owner_source'] ?? null)->toBe('restore')
        ->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'priority'))->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'default'))->toBeTrue();
});

test('clearRuntimeState 先清全部目标队列，再清缓存、重建暂停标记并回写进度', function () {
    $manager = app(RestoreRuntimeManager::class);
    $manager->freeze('atomic restore');

    Queue::connection('restore_database')->pushOn('priority', new ProbeTriesFiveNoMaxJob);
    Queue::connection('restore_database')->pushOn('default', new ProbeTriesFiveNoMaxJob);
    Cache::forever('runtime-sentinel', 'stale');
    Cache::forever('restore-progress', ['stage' => 'before-flush']);

    $manager->clearRuntimeState(function () {
        expect(Queue::connection('restore_database')->size('priority'))->toBe(0)
            ->and(Queue::connection('restore_database')->size('default'))->toBe(0)
            ->and(Cache::get('runtime-sentinel'))->toBeNull()
            ->and(Cache::get('restore-progress'))->toBeNull()
            ->and(Queue::isPaused('restore_database', 'priority'))->toBeTrue()
            ->and(Queue::isPaused('restore_database', 'default'))->toBeTrue();

        Cache::forever('restore-progress', ['stage' => 'runtime_cleanup']);
    });

    expect(Cache::get('restore-progress'))->toBe(['stage' => 'runtime_cleanup'])
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and(app()->isDownForMaintenance())->toBeTrue();
});

test('clearRuntimeState 回写进度失败时分类报错并保持恢复冻结', function () {
    $manager = app(RestoreRuntimeManager::class);
    $manager->freeze('atomic restore');

    expect(fn () => $manager->clearRuntimeState(
        fn () => throw new RuntimeException('progress backend failed'),
    ))->toThrow(RuntimeException::class, '数据库已验证、运行时清理待重试');

    expect(UpgradeFreezeLock::info()['owner_source'] ?? null)->toBe('restore')
        ->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'priority'))->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'default'))->toBeTrue();
});

test('不支持清空的实际队列驱动同样分类失败且不退出冻结', function () {
    config([
        'queue.default' => 'sync',
        'queue.names' => ['default' => 'default'],
    ]);
    $manager = app(RestoreRuntimeManager::class);
    $manager->freeze('atomic restore');

    expect(fn () => $manager->clearRuntimeState(fn () => null))
        ->toThrow(RuntimeException::class, '数据库已验证、运行时清理待重试');

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(Queue::isPaused('sync', 'default'))->toBeTrue();
});

test('resume 严格先解除恢复锁、退出维护，再恢复全部队列', function () {
    $manager = app(RestoreRuntimeManager::class);
    $manager->freeze('atomic restore');
    $events = [];
    Event::listen(QueueResumed::class, function (QueueResumed $event) use (&$events) {
        $events[] = [$event->connection, $event->queue];
        expect(UpgradeFreezeLock::isFrozen())->toBeFalse()
            ->and(app()->isDownForMaintenance())->toBeFalse();
    });

    $manager->resume();

    expect($events)->toBe([
        ['restore_database', 'priority'],
        ['restore_database', 'default'],
    ])->and(Queue::isPaused('restore_database', 'priority'))->toBeFalse()
        ->and(Queue::isPaused('restore_database', 'default'))->toBeFalse();
});

test('resume 恢复队列异常时重新建立完整冻结', function () {
    $manager = app(RestoreRuntimeManager::class);
    $manager->freeze('atomic restore');
    $throwOnce = true;
    Event::listen(QueueResumed::class, function () use (&$throwOnce) {
        if ($throwOnce) {
            $throwOnce = false;
            throw new RuntimeException('resume failed');
        }
    });

    expect(fn () => $manager->resume())
        ->toThrow(RuntimeException::class, '数据库恢复退出失败，系统保持冻结');

    expect(UpgradeFreezeLock::info()['owner_source'] ?? null)->toBe('restore')
        ->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'priority'))->toBeTrue()
        ->and(Queue::isPaused('restore_database', 'default'))->toBeTrue();
});

test('assertStillFrozen 同时校验恢复锁、维护模式和所有暂停标记', function () {
    $manager = app(RestoreRuntimeManager::class);
    $manager->freeze('atomic restore');
    $manager->assertStillFrozen();

    Queue::resume('restore_database', 'priority');

    expect(fn () => $manager->assertStillFrozen())
        ->toThrow(RuntimeException::class, '恢复运行时冻结状态不完整');
});
