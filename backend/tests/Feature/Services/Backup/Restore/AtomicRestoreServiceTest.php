<?php

declare(strict_types=1);

use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseOperationMutex;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\NativeProcessPipeline;
use App\Services\Backup\Restore\AtomicRestoreService;
use App\Services\Backup\Restore\RestoreForeignKeyPlanStore;
use App\Services\Backup\Restore\RestorePreflight;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreRuntimeManager;
use App\Services\Backup\Restore\RestoreSchemaManager;
use App\Services\Backup\Restore\RestoreState;
use App\Services\Backup\Restore\RestoreStateInspector;
use App\Services\Backup\Restore\RestoreValidator;
use Tests\Support\Backup\AtomicRestoreFailureHarness;

beforeEach(function () {
    $this->restoreFake = new AtomicRestoreFailureHarness;
    foreach ([
        RestorePreflight::class,
        RestoreForeignKeyPlanStore::class,
        DatabaseOperationMutex::class,
        RestoreStateInspector::class,
        MysqlToolchainChecker::class,
        BackupService::class,
        RestoreRuntimeManager::class,
        RestoreSchemaManager::class,
        RestoreValidator::class,
        NativeProcessPipeline::class,
    ] as $service) {
        $this->app->instance($service, $this->restoreFake);
    }
});

it('按唯一顺序完成新恢复并发布固定阶段', function (): void {
    $progress = [];

    $result = app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'admin:1'),
        function (array $payload) use (&$progress): void {
            $progress[] = $payload;
        },
    );

    expect($result->operation)->toBe('restored')
        ->and($result->restoreToken)->toBe(AtomicRestoreFailureHarness::INITIAL_TOKEN)
        ->and($this->restoreFake->calls)->toBe([
            'preflight.inspect',
            'preflight.assert',
            'mutex.acquire',
            'state.inspect',
            'toolchain.inspect',
            'backup.resolve',
            'runtime.freeze',
            'schema.create_runtime',
            'pipeline.import',
            'schema.normalize_identity',
            'validator.shadow',
            'schema.prepare_fk',
            'schema.cutover',
            'validator.active',
            'runtime.cleanup',
            'schema.drop_old',
            'runtime.resume',
            'mutex.release',
        ])
        ->and(array_values(array_unique(array_column($progress, 'stage'))))->toBe([
            'preflight',
            'freeze',
            'create_shadow',
            'import',
            'prepare_structure',
            'validate',
            'wait_metadata_lock',
            'cutover',
            'runtime_cleanup',
            'complete',
        ]);
});

it('导入失败时丢弃完整 staged 表并保持恢复冻结', function (): void {
    $this->restoreFake->failAt = 'pipeline.import';
    $this->restoreFake->states = [RestoreState::Clean, RestoreState::Staged];

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, 'pipeline.import failed');

    expect($this->restoreFake->calls)->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('schema.cutover')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('流式导入结束后拒绝缺少权威 Schema 声明的源表', function (): void {
    $directory = storage_path('framework/testing/atomic-restore-import');
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    $sqlPath = $directory.'/missing-source-table.sql.gz';
    $mysqlPath = $directory.'/mysql-sink';
    file_put_contents($sqlPath, gzencode('', 1));
    file_put_contents($mysqlPath, "#!/bin/sh\ncat >/dev/null\n");
    chmod($mysqlPath, 0700);

    $this->app->instance(NativeProcessPipeline::class, new NativeProcessPipeline);
    $this->app->instance(MysqlToolchainChecker::class, new class($mysqlPath)
    {
        public function __construct(private readonly string $mysqlPath) {}

        public function inspect(mixed ...$arguments): array
        {
            return [
                'supported' => true,
                'errors' => [],
                'mysql' => ['path' => $this->mysqlPath],
                'gzip' => ['path' => '/usr/bin/gzip'],
            ];
        }
    });
    $this->app->instance(BackupService::class, new class($sqlPath)
    {
        public function __construct(private readonly string $sqlPath) {}

        public function resolveBackup(string $backupId): array
        {
            return ['id' => $backupId, 'sql' => $this->sqlPath, 'schema' => null];
        }
    });
    $this->restoreFake->states = [RestoreState::Clean, RestoreState::Staged];

    try {
        expect(fn () => app(AtomicRestoreService::class)->restore(
            new RestoreRequest('backup_20260830_120000', false, 'cli'),
            fn (array $payload) => null,
        ))->toThrow(RuntimeException::class, 'SQL 未包含 Schema 声明的全部源表');

        expect($this->restoreFake->calls)->toContain('schema.drop_staged')
            ->and($this->restoreFake->calls)->not->toContain('schema.cutover')
            ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
    } finally {
        @unlink($sqlPath);
        @unlink($mysqlPath);
        @rmdir($directory);
    }
});

it('影子校验失败时把脱敏报告交给同步 CLI 且保持现用库不变', function (): void {
    $this->restoreFake->shadowValidationPasses = false;
    $this->restoreFake->shadowValidationErrors = [[
        'code' => 'transaction_balance_arithmetic',
        'message' => '交易前后余额与金额不守恒。',
        'count' => 17,
    ]];
    $this->restoreFake->states = [RestoreState::Clean, RestoreState::Staged];

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(
        RuntimeException::class,
        '恢复 shadow 校验失败：[{"code":"transaction_balance_arithmetic","message":"交易前后余额与金额不守恒。","count":17}]',
    );

    expect($this->restoreFake->calls)->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('schema.cutover')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('rename 前失败时恢复 active 外键再丢弃 staged 表', function (): void {
    $this->restoreFake->failAt = 'schema.cutover';
    $this->restoreFake->states = [RestoreState::Clean, RestoreState::ShadowForeignKeysReady];

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, 'schema.cutover failed');

    $restoreIndex = array_search('schema.restore_active_fk', $this->restoreFake->calls, true);
    $dropIndex = array_search('schema.drop_staged', $this->restoreFake->calls, true);
    expect($restoreIndex)->toBeInt()
        ->and($dropIndex)->toBeInt()
        ->and($restoreIndex)->toBeLessThan($dropIndex)
        ->and($this->restoreFake->calls)->not->toContain('runtime.cleanup')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('切换后 active 校验失败时原子回滚并丢弃无效 staged 表', function (): void {
    $this->restoreFake->activeValidationPasses = false;

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, '恢复 active 校验失败');

    expect($this->restoreFake->calls)->toContain('schema.rollback')
        ->and($this->restoreFake->calls)->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('runtime.cleanup')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('切换后 active 校验器异常也必须回滚而不是留下未确认的新库', function (): void {
    $this->restoreFake->failAt = 'validator.active';

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, 'validator.active failed');

    expect($this->restoreFake->calls)->toContain('schema.rollback')
        ->and($this->restoreFake->calls)->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('runtime.cleanup')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('导入中断留下不完整 shadow 集时仅清理 shadow 而不按 incomplete old 阻断', function (): void {
    $this->restoreFake->failAt = 'pipeline.import';
    $this->restoreFake->states = [RestoreState::Clean, RestoreState::BrokenOldSet];

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, 'pipeline.import failed');

    expect($this->restoreFake->calls)->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('schema.restore_active_fk')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('rename 前补偿状态不确定时继续保持恢复冻结', function (): void {
    $this->restoreFake->failAt = 'pipeline.import';
    $this->restoreFake->states = [RestoreState::Clean, RestoreState::BrokenOldSet];
    $this->restoreFake->ambiguousBrokenOldSet = true;

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, 'rename 前补偿状态不确定');

    expect($this->restoreFake->calls)->not->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('运行时清理失败时不回滚已验证 active 或删除 old', function (): void {
    $this->restoreFake->failAt = 'runtime.cleanup';

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, 'runtime.cleanup failed');

    expect($this->restoreFake->calls)->not->toContain('schema.rollback')
        ->and($this->restoreFake->calls)->not->toContain('schema.drop_old')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('ActiveWithOld 续跑绑定已有 token 并跳过导入直接完成清理', function (): void {
    $this->restoreFake->states = [RestoreState::ActiveWithOld];
    $this->restoreFake->observedToken = AtomicRestoreFailureHarness::RESUME_TOKEN;

    $result = app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', true, 'cli'),
        fn (array $payload) => null,
    );

    expect($result->operation)->toBe('resumed')
        ->and($result->restoreToken)->toBe(AtomicRestoreFailureHarness::RESUME_TOKEN)
        ->and($this->restoreFake->seenContextTokens)->not->toBeEmpty()
        ->and(array_unique($this->restoreFake->seenContextTokens))->toBe([AtomicRestoreFailureHarness::RESUME_TOKEN])
        ->and($this->restoreFake->calls)->not->toContain('pipeline.import')
        ->and($this->restoreFake->calls)->not->toContain('schema.create_runtime')
        ->and($this->restoreFake->calls)->toContain('validator.active')
        ->and($this->restoreFake->calls)->toContain('runtime.cleanup')
        ->and($this->restoreFake->calls)->toContain('schema.drop_old');
});

it('互斥锁被占用时在冻结运行时之前停止', function (): void {
    $this->restoreFake->mutexAcquired = false;

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, '已有备份/恢复任务在执行');

    expect($this->restoreFake->calls)->toBe([
        'preflight.inspect',
        'preflight.assert',
        'mutex.acquire',
    ]);
});

it('ActiveForeignKeysRemoved 续跑时不重复导入并直接完成外键与切换', function (): void {
    $this->restoreFake->states = [RestoreState::ActiveForeignKeysRemoved];
    $this->restoreFake->observedToken = AtomicRestoreFailureHarness::RESUME_TOKEN;

    $result = app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    );

    expect($result->operation)->toBe('resumed')
        ->and($result->restoreToken)->toBe(AtomicRestoreFailureHarness::RESUME_TOKEN)
        ->and($this->restoreFake->calls)->not->toContain('pipeline.import')
        ->and($this->restoreFake->calls)->not->toContain('schema.create_runtime')
        ->and($this->restoreFake->calls)->toContain('schema.prepare_fk')
        ->and($this->restoreFake->calls)->toContain('schema.cutover')
        ->and($this->restoreFake->calls)->toContain('validator.active');
});

it('BrokenOldSet 续跑时保持冻结且不做任何删表或切换', function (): void {
    $this->restoreFake->states = [RestoreState::BrokenOldSet];

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, '恢复表集合不完整');

    expect($this->restoreFake->calls)->toContain('runtime.freeze')
        ->and($this->restoreFake->calls)->not->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('schema.drop_old')
        ->and($this->restoreFake->calls)->not->toContain('schema.cutover')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});

it('SIGTERM 在可用时经异常展开清理子流程并留下可续跑 staged 状态', function (): void {
    if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('需要 pcntl/posix');
    }
    $this->restoreFake->sendSigtermDuringImport = true;
    $this->restoreFake->states = [RestoreState::Clean, RestoreState::Staged];

    expect(fn () => app(AtomicRestoreService::class)->restore(
        new RestoreRequest('backup_20260830_120000', false, 'cli'),
        fn (array $payload) => null,
    ))->toThrow(RuntimeException::class, '数据库恢复收到终止信号');

    expect($this->restoreFake->calls)->toContain('schema.drop_staged')
        ->and($this->restoreFake->calls)->not->toContain('runtime.resume');
});
