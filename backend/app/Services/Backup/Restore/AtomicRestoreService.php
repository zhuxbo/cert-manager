<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseOperationMutex;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\NativeProcessPipeline;
use App\Services\Backup\PipelineResult;
use App\Services\Backup\SqlDumpRewritePlan;
use App\Services\Backup\SqlDumpRewriter;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class AtomicRestoreService
{
    private const IDLE_TIMEOUT_SECONDS = 60;

    private const MAX_WARNINGS = 20;

    public function restore(RestoreRequest $request, callable $progress): RestoreResult
    {
        $lastProgress = [];
        $emit = function (string $stage, string $message, array $metrics = []) use ($progress, &$lastProgress): void {
            $lastProgress = [
                'stage' => $stage,
                'message' => $message,
                'metrics' => $metrics,
                'updated_at' => now()->toDateTimeString(),
            ];
            $progress($lastProgress);
        };

        $emit('preflight', '检查备份、工具链、Schema 和恢复状态');
        $preflight = app(RestorePreflight::class);
        $report = $preflight->inspect($request);
        $context = $report['context'] ?? null;
        if (! $context instanceof RestoreContext) {
            throw new RuntimeException('恢复预检未生成有效上下文');
        }
        $preflight->assertRunnable($this->continuationReport($report), $request);

        $mutex = app(DatabaseOperationMutex::class);
        if (! $mutex->acquire()) {
            throw new RuntimeException('已有备份/恢复任务在执行，请稍后再试');
        }

        try {
            return $this->withTerminationGuard(fn (): RestoreResult => $this->runLocked(
                $request,
                $report,
                $context,
                $emit,
                $progress,
                $lastProgress,
            ));
        } finally {
            $mutex->release();
        }
    }

    private function runLocked(
        RestoreRequest $request,
        array $report,
        RestoreContext $context,
        callable $emit,
        callable $progress,
        array &$lastProgress,
    ): RestoreResult {
        $stateInspector = app(RestoreStateInspector::class);
        $stateFacts = $stateInspector->inspect($context);
        $state = $this->state($stateFacts);
        $context = $this->bindObservedToken($context, $stateFacts, $state);

        $toolchain = app(MysqlToolchainChecker::class)->inspect(requireMysql: true, requireMysqldump: false);
        if (! $toolchain['supported'] || $toolchain['mysql'] === null) {
            throw new RuntimeException('MySQL 工具链不受支持：'.implode('；', $toolchain['errors']));
        }
        $backup = app(BackupService::class)->resolveBackup($request->backupId);
        if ($backup === null) {
            throw new RuntimeException('备份不存在或已失效');
        }

        $runtime = app(RestoreRuntimeManager::class);
        $runtime->freeze('database restore: '.$request->backupId);
        $emit('freeze', '数据库写入、队列和维护模式已冻结');

        if ($state === RestoreState::BrokenOldSet) {
            throw new RuntimeException('恢复表集合不完整，已保持冻结等待人工检查');
        }

        $schema = app(RestoreSchemaManager::class);
        $validator = app(RestoreValidator::class);
        $operation = $state === RestoreState::Clean || $state === RestoreState::Staged
            ? 'restored'
            : 'resumed';
        $pipelineResult = null;
        $shadowReport = null;
        $activeReport = null;
        $databaseValidated = false;
        $compensated = false;
        $cutoverCompleted = $state === RestoreState::ActiveWithOld;

        try {
            if ($state === RestoreState::ActiveWithOld) {
                $emit('validate', '校验已切换的现用数据库');
                $activeReport = $validator->validateActive($context);
                if (! $activeReport->passed) {
                    $schema->rollback($context);
                    $schema->dropStagedTables($context);
                    $compensated = true;
                    throw new RuntimeException('恢复 active 校验失败，已原子回滚');
                }
                $databaseValidated = true;
            } elseif (in_array($state, [RestoreState::ActiveForeignKeysRemoved, RestoreState::ShadowForeignKeysReady], true)) {
                $emit('wait_metadata_lock', '续接外键准备并等待元数据锁');
                $schema->prepareCanonicalForeignKeys($context);
                $emit('cutover', '原子切换全部恢复表');
                $schema->cutover($context);
                $cutoverCompleted = true;
                $emit('validate', '校验切换后的现用数据库');
                $activeReport = $validator->validateActive($context);
                if (! $activeReport->passed) {
                    $schema->rollback($context);
                    $schema->dropStagedTables($context);
                    $compensated = true;
                    throw new RuntimeException('恢复 active 校验失败，已原子回滚');
                }
                $databaseValidated = true;
            } else {
                if ($state === RestoreState::Staged) {
                    $schema->dropStagedTables($context);
                }
                $emit('create_shadow', '创建空运行时影子表');
                $schema->prepareEmptyRuntimeShadows($context);

                $emit('import', '流式导入备份到影子表');
                $pipelineResult = $this->import($backup['sql'], $toolchain, $context, function (
                    int $inputBytes,
                    int $outputBytes,
                ) use ($emit): void {
                    $emit('import', '流式导入备份到影子表', [
                        'input_bytes' => $inputBytes,
                        'output_bytes' => $outputBytes,
                    ]);
                });

                $emit('prepare_structure', '整理身份游标和恢复结构');
                $schema->normalizeIdentityState($context, CarbonImmutable::now());
                $emit('validate', '校验影子数据库');
                $shadowReport = $validator->validateShadow($context);
                if (! $shadowReport->passed) {
                    $details = json_encode(
                        $shadowReport->errors,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    );
                    throw new RuntimeException(
                        '恢复 shadow 校验失败'.($details === false ? '' : '：'.$details),
                    );
                }

                $emit('wait_metadata_lock', '准备规范外键并等待元数据锁');
                $schema->prepareCanonicalForeignKeys($context);
                $emit('cutover', '原子切换全部恢复表');
                $schema->cutover($context);
                $cutoverCompleted = true;
                $activeReport = $validator->validateActive($context);
                if (! $activeReport->passed) {
                    $schema->rollback($context);
                    $schema->dropStagedTables($context);
                    $compensated = true;
                    throw new RuntimeException('恢复 active 校验失败，已原子回滚');
                }
                $databaseValidated = true;
            }

            $emit('runtime_cleanup', '清理队列和应用缓存');
            $runtime->clearRuntimeState(function () use ($progress, &$lastProgress): void {
                $progress($lastProgress);
            });
            $schema->dropOldTables($context);
            $runtime->resume();

            $metrics = $this->metrics($context, $pipelineResult, $activeReport);
            $emit('complete', '数据库恢复完成', $metrics);

            return new RestoreResult(
                $operation,
                $context->restoreToken,
                $this->warnings($report, $shadowReport, $activeReport),
                $metrics,
            );
        } catch (Throwable $failure) {
            if (! $databaseValidated && ! $compensated) {
                if ($cutoverCompleted) {
                    try {
                        $schema->rollback($context);
                        $schema->dropStagedTables($context);
                    } catch (Throwable $compensationFailure) {
                        throw new RuntimeException(
                            '恢复切换后校验失败且无法完整回滚: '.$compensationFailure->getMessage(),
                            0,
                            $failure,
                        );
                    }
                } else {
                    $this->compensateBeforeCutover($context, $stateInspector, $schema, $failure);
                }
            }

            throw $failure;
        }
    }

    private function import(string $sqlPath, array $toolchain, RestoreContext $context, callable $progress): PipelineResult
    {
        $mysql = $toolchain['mysql']['path'] ?? null;
        $gzip = $toolchain['gzip']['path'] ?? null;
        if (! is_string($mysql) || $mysql === '' || ! is_string($gzip) || $gzip === '') {
            throw new RuntimeException('恢复工具链路径无效');
        }
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);
        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('恢复数据库配置缺少 database');
        }

        $tableMap = [];
        $columnOrder = [];
        $generatedColumns = [];
        foreach ($context->sourceTables as $table) {
            $tableMap[$table] = $context->shadowTableMap[$table];
            $columnOrder[$table] = $context->columnOrder[$table];
            $generatedColumns[$table] = $context->generatedColumns[$table];
        }
        $rewriter = new SqlDumpRewriter(new SqlDumpRewritePlan(
            $tableMap,
            $columnOrder,
            $generatedColumns,
        ));

        $cnfPath = $this->writeClientConfig($config);
        try {
            $result = app(NativeProcessPipeline::class)->restoreFromGzip(
                [$gzip, '-dc', $sqlPath],
                [
                    $mysql,
                    "--defaults-extra-file={$cnfPath}",
                    '--default-character-set=utf8mb4',
                    $database,
                ],
                $rewriter,
                $progress,
                self::IDLE_TIMEOUT_SECONDS,
            );
            $missingTables = array_values(array_diff(
                $context->sourceTables,
                $rewriter->encounteredTables(),
            ));
            if ($missingTables !== []) {
                throw new RuntimeException('SQL 未包含 Schema 声明的全部源表');
            }

            return $result;
        } finally {
            @unlink($cnfPath);
        }
    }

    /**
     * @param  RestoreStateInspector  $stateInspector
     * @param  RestoreSchemaManager  $schema
     */
    private function compensateBeforeCutover(
        RestoreContext $context,
        mixed $stateInspector,
        mixed $schema,
        Throwable $primaryFailure,
    ): void {
        try {
            $facts = $stateInspector->inspect($context);
            $state = $this->state($facts);
            if (in_array($state, [RestoreState::ActiveForeignKeysRemoved, RestoreState::ShadowForeignKeysReady], true)) {
                $schema->restoreActiveForeignKeys($context);
                $schema->dropStagedTables($context);
            } elseif ($state === RestoreState::Staged) {
                $schema->dropStagedTables($context);
            } elseif ($state === RestoreState::BrokenOldSet
                && $this->isOnlyIncompleteStaged($context, $facts)) {
                $schema->dropStagedTables($context);
            } elseif ($state !== RestoreState::Clean) {
                throw new RuntimeException('rename 前补偿状态不确定: '.$state->value);
            }
        } catch (Throwable $compensationFailure) {
            throw new RuntimeException(
                '恢复失败且 rename 前补偿未完成: '.$compensationFailure->getMessage(),
                0,
                $primaryFailure,
            );
        }
    }

    private function isOnlyIncompleteStaged(RestoreContext $context, array $facts): bool
    {
        $tokens = $facts['tokens'] ?? null;
        $shadowTables = $facts['shadow_tables'] ?? null;

        return is_array($tokens)
            && $tokens === [$context->restoreToken]
            && is_array($shadowTables)
            && $shadowTables !== []
            && ($facts['old_tables'] ?? null) === []
            && ($facts['unexpected_shadow_suffixes'] ?? null) === []
            && ($facts['malformed_namespace_tables'] ?? null) === [];
    }

    private function continuationReport(array $report): array
    {
        $state = $report['state']['state'] ?? null;
        if (! in_array($state, array_map(
            static fn (RestoreState $candidate): string => $candidate->value,
            RestoreState::cases(),
        ), true) || $state === RestoreState::Clean->value) {
            return $report;
        }

        $report['hard_blockers'] = array_values(array_filter(
            $report['hard_blockers'] ?? [],
            static fn (mixed $blocker): bool => ! is_array($blocker)
                || ($blocker['code'] ?? null) !== 'restore_state_not_clean',
        ));

        return $report;
    }

    private function bindObservedToken(
        RestoreContext $context,
        array $stateFacts,
        RestoreState $state,
    ): RestoreContext {
        if ($state === RestoreState::Clean) {
            return $context;
        }
        $tokens = $stateFacts['tokens'] ?? null;
        if (! is_array($tokens) || count($tokens) !== 1 || ! is_string($tokens[0])
            || preg_match('/^[a-f0-9]{12}$/D', $tokens[0]) !== 1) {
            throw new RuntimeException('中断恢复 token 状态不唯一');
        }
        $token = $tokens[0];
        $shadow = [];
        $old = [];
        foreach ($context->swapTables() as $table) {
            $shadow[$table] = "__rst_{$token}_{$table}";
            $old[$table] = "__old_{$token}_{$table}";
        }

        return new RestoreContext(
            $token,
            $context->artifactSha256,
            $context->schema,
            $context->schemaAuthoritative,
            $context->sourceTables,
            $context->businessTables,
            $context->runtimeResetTables,
            $context->retainedLogTables,
            $shadow,
            $old,
            $context->columnOrder,
            $context->generatedColumns,
            $context->desiredForeignKeys,
            $context->legacyAdjunctTables,
        );
    }

    private function state(array $facts): RestoreState
    {
        $value = $facts['state'] ?? null;
        if (! is_string($value) || RestoreState::tryFrom($value) === null) {
            throw new RuntimeException('无法识别数据库恢复状态');
        }

        return RestoreState::from($value);
    }

    private function writeClientConfig(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mysql_restore_');
        if ($path === false) {
            throw new RuntimeException('无法创建 MySQL 临时配置文件');
        }
        $escape = static fn (string $value): string => str_replace(
            ['\\', '"', "\n", "\r"],
            ['\\\\', '\\"', '\\n', '\\r'],
            $value,
        );
        $content = "[client]\n"
            .'host='.$escape((string) ($config['host'] ?? '127.0.0.1'))."\n"
            .'port='.$escape((string) ($config['port'] ?? '3306'))."\n"
            .'user='.$escape((string) ($config['username'] ?? ''))."\n"
            .'password="'.$escape((string) ($config['password'] ?? '')).'"'."\n"
            .'default-character-set='.$escape((string) ($config['charset'] ?? 'utf8mb4'))."\n";
        if (! @chmod($path, 0600) || @file_put_contents($path, $content, LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('无法写入 MySQL 临时配置文件');
        }

        return $path;
    }

    private function withTerminationGuard(callable $callback): RestoreResult
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_async_signals')) {
            return $callback();
        }

        $previousHandler = pcntl_signal_get_handler(SIGTERM);
        $previousAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): never {
            throw new RuntimeException('数据库恢复收到终止信号');
        });
        try {
            return $callback();
        } finally {
            pcntl_signal(SIGTERM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }

    private function metrics(
        RestoreContext $context,
        ?PipelineResult $pipeline,
        ?RestoreValidationReport $active,
    ): array {
        return [
            'tables' => count($context->swapTables()),
            'input_bytes' => $pipeline instanceof PipelineResult ? $pipeline->inputBytes : 0,
            'output_bytes' => $pipeline instanceof PipelineResult ? $pipeline->outputBytes : 0,
            'checked_tables' => $active instanceof RestoreValidationReport
                ? (int) ($active->metrics['checked_tables'] ?? 0)
                : 0,
        ];
    }

    /** @return list<array{code:string,message:string}> */
    private function warnings(
        array $report,
        ?RestoreValidationReport $shadow,
        ?RestoreValidationReport $active,
    ): array {
        $warnings = array_merge(
            is_array($report['warnings'] ?? null) ? $report['warnings'] : [],
            $shadow instanceof RestoreValidationReport ? $shadow->warnings : [],
            $active instanceof RestoreValidationReport ? $active->warnings : [],
        );
        $safe = [];
        foreach (array_slice($warnings, 0, self::MAX_WARNINGS) as $warning) {
            if (! is_array($warning)) {
                continue;
            }
            $safe[] = [
                'code' => substr((string) ($warning['code'] ?? 'warning'), 0, 64),
                'message' => mb_substr((string) ($warning['message'] ?? '恢复校验警告'), 0, 500),
            ];
        }

        return $safe;
    }
}
