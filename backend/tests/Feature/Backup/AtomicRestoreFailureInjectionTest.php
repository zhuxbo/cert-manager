<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseOperationMutex;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\NativeProcessPipeline;
use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestorePreflight;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreRuntimeManager;
use App\Services\Backup\Restore\RestoreSchemaManager;
use App\Services\Backup\Restore\RestoreState;
use App\Services\Backup\Restore\RestoreStateInspector;
use App\Services\Backup\Restore\RestoreValidator;
use Tests\Support\Backup\AtomicRestoreFailureHarness;

function task12BindFailureHarness(): AtomicRestoreFailureHarness
{
    $harness = new AtomicRestoreFailureHarness;
    foreach ([
        RestorePreflight::class,
        DatabaseOperationMutex::class,
        RestoreStateInspector::class,
        MysqlToolchainChecker::class,
        BackupService::class,
        RestoreRuntimeManager::class,
        RestoreSchemaManager::class,
        RestoreValidator::class,
        NativeProcessPipeline::class,
    ] as $service) {
        app()->instance($service, $harness);
    }

    return $harness;
}

/** @return 'unchanged_active_frozen'|'complete_rollback_frozen'|'validated_new_frozen'|'invalid' */
function task12FailureOutcome(array $calls): string
{
    if (in_array('schema.rollback', $calls, true)
        && in_array('schema.drop_staged', $calls, true)
        && ! in_array('runtime.resume', $calls, true)) {
        return 'complete_rollback_frozen';
    }
    if (in_array('validator.active', $calls, true)
        && in_array('runtime.cleanup', $calls, true)
        && ! in_array('schema.rollback', $calls, true)
        && ! in_array('schema.drop_old', $calls, true)
        && ! in_array('runtime.resume', $calls, true)) {
        return 'validated_new_frozen';
    }
    if (! in_array('validator.active', $calls, true)
        && ! in_array('runtime.resume', $calls, true)
        && (in_array('schema.drop_staged', $calls, true)
            || ! in_array('schema.create_runtime', $calls, true))) {
        return 'unchanged_active_frozen';
    }

    return 'invalid';
}

test('所有恢复故障点只落入三类允许结果', function (
    string $failurePoint,
    array $states,
    string $expectedOutcome,
): void {
    $harness = task12BindFailureHarness();
    $harness->failAt = $failurePoint;
    $harness->states = $states;

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'failure-injection'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class);

    expect(task12FailureOutcome($harness->calls))->toBe($expectedOutcome)
        ->and($harness->calls)->not->toContain('runtime.resume');
})->with([
    '建 shadow' => ['schema.create_runtime', [RestoreState::Clean, RestoreState::BrokenOldSet], 'unchanged_active_frozen'],
    '导入中点' => ['pipeline.import', [RestoreState::Clean, RestoreState::Staged], 'unchanged_active_frozen'],
    'gzip EOF' => ['pipeline.import', [RestoreState::Clean, RestoreState::Staged], 'unchanged_active_frozen'],
    '生成列改写' => ['pipeline.import', [RestoreState::Clean, RestoreState::Staged], 'unchanged_active_frozen'],
    'shadow 校验' => ['validator.shadow', [RestoreState::Clean, RestoreState::Staged], 'unchanged_active_frozen'],
    'active FK 删除后' => ['schema.prepare_fk', [RestoreState::Clean, RestoreState::ActiveForeignKeysRemoved], 'unchanged_active_frozen'],
    'shadow FK 添加后' => ['schema.prepare_fk', [RestoreState::Clean, RestoreState::ShadowForeignKeysReady], 'unchanged_active_frozen'],
    'metadata lock 超时' => ['schema.cutover', [RestoreState::Clean, RestoreState::ShadowForeignKeysReady], 'unchanged_active_frozen'],
    'rename 后校验异常' => ['validator.active', [RestoreState::Clean], 'complete_rollback_frozen'],
    'runtime cleanup' => ['runtime.cleanup', [RestoreState::Clean], 'validated_new_frozen'],
]);

test('SIGTERM 展开为异常并保持原 active', function (): void {
    if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('需要 pcntl/posix');
    }
    $harness = task12BindFailureHarness();
    $harness->sendSigtermDuringImport = true;
    $harness->states = [RestoreState::Clean, RestoreState::Staged];

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'sigterm'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, '数据库恢复收到终止信号');

    expect(task12FailureOutcome($harness->calls))->toBe('unchanged_active_frozen')
        ->and($harness->calls)->not->toContain('runtime.resume');
});
