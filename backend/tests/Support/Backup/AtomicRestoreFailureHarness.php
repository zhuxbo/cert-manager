<?php

declare(strict_types=1);

namespace Tests\Support\Backup;

use App\Services\Backup\PipelineResult;
use App\Services\Backup\Restore\RestoreContext;
use App\Services\Backup\Restore\RestoreCutoverResult;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreState;
use App\Services\Backup\Restore\RestoreValidationReport;
use App\Services\Backup\SqlStreamTransformer;
use RuntimeException;

final class AtomicRestoreFailureHarness
{
    public const INITIAL_TOKEN = 'aabbccddeeff';

    public const RESUME_TOKEN = '112233445566';

    /** @var list<string> */
    public array $calls = [];

    /** @var list<RestoreState> */
    public array $states = [RestoreState::Clean];

    /** @var list<string> */
    public array $seenContextTokens = [];

    public ?string $failAt = null;

    public ?string $observedToken = null;

    public bool $activeValidationPasses = true;

    public bool $shadowValidationPasses = true;

    /** @var list<array<string, mixed>> */
    public array $shadowValidationErrors = [];

    public bool $sendSigtermDuringImport = false;

    public bool $mutexAcquired = true;

    public bool $ambiguousBrokenOldSet = false;

    private int $stateIndex = 0;

    public function inspect(mixed ...$arguments): mixed
    {
        if (($arguments[0] ?? null) instanceof RestoreRequest) {
            $this->record('preflight.inspect');

            return [
                'hard_blockers' => [],
                'confirmations' => [],
                'warnings' => [['code' => 'fixture_warning', 'message' => 'fixture']],
                'context' => $this->context(self::INITIAL_TOKEN),
                'state' => $this->statePayload($this->states[0]),
            ];
        }
        if (($arguments[0] ?? null) instanceof RestoreContext) {
            $this->record('state.inspect');
            $state = $this->states[min($this->stateIndex, count($this->states) - 1)];
            $this->stateIndex++;

            return $this->statePayload($state);
        }

        $this->record('toolchain.inspect');

        return [
            'supported' => true,
            'errors' => [],
            'mysql' => ['path' => '/bin/true'],
            'gzip' => ['path' => '/bin/true'],
        ];
    }

    public function assertRunnable(array $report, RestoreRequest $request): void
    {
        $this->record('preflight.assert');
    }

    public function load(RestoreContext $context): array
    {
        $this->remember($context);
        $this->record('plan.load');

        return [];
    }

    public function acquire(int $waitSeconds = 0): bool
    {
        $this->record('mutex.acquire');

        return $this->mutexAcquired;
    }

    public function release(): void
    {
        $this->record('mutex.release');
    }

    public function resolveBackup(string $backupId): array
    {
        $this->record('backup.resolve');

        return ['id' => $backupId, 'sql' => '/tmp/fake-backup.sql.gz', 'schema' => null];
    }

    public function freeze(string $reason): void
    {
        $this->record('runtime.freeze');
    }

    public function clearRuntimeState(callable $republishProgress): void
    {
        $this->record('runtime.cleanup');
        $republishProgress();
    }

    public function resume(): void
    {
        $this->record('runtime.resume');
    }

    public function assertStillFrozen(): void {}

    public function prepareEmptyRuntimeShadows(RestoreContext $context): void
    {
        $this->remember($context);
        $this->record('schema.create_runtime');
    }

    public function normalizeIdentityState(RestoreContext $context, mixed $restoredAt): void
    {
        $this->remember($context);
        $this->record('schema.normalize_identity');
    }

    public function prepareCanonicalForeignKeys(RestoreContext $context): void
    {
        $this->remember($context);
        $this->record('schema.prepare_fk');
    }

    public function cutover(RestoreContext $context): RestoreCutoverResult
    {
        $this->remember($context);
        $this->record('schema.cutover');

        return new RestoreCutoverResult('cutover', $context->swapTables());
    }

    public function rollback(RestoreContext $context): RestoreCutoverResult
    {
        $this->remember($context);
        $this->record('schema.rollback');

        return new RestoreCutoverResult('rollback', $context->swapTables());
    }

    public function restoreActiveForeignKeys(RestoreContext $context): void
    {
        $this->remember($context);
        $this->record('schema.restore_active_fk');
    }

    public function dropStagedTables(RestoreContext $context): void
    {
        $this->remember($context);
        $this->record('schema.drop_staged');
    }

    public function dropOldTables(RestoreContext $context): void
    {
        $this->remember($context);
        $this->record('schema.drop_old');
    }

    public function validateShadow(RestoreContext $context): RestoreValidationReport
    {
        $this->remember($context);
        $this->record('validator.shadow');

        return new RestoreValidationReport(
            $this->shadowValidationPasses,
            $this->shadowValidationErrors,
            [],
            ['scope' => 'shadow'],
        );
    }

    public function validateActive(RestoreContext $context): RestoreValidationReport
    {
        $this->remember($context);
        $this->record('validator.active');

        return new RestoreValidationReport(
            $this->activeValidationPasses,
            $this->activeValidationPasses ? [] : [['code' => 'active_failed']],
            [],
            ['scope' => 'active'],
        );
    }

    public function restoreFromGzip(mixed ...$arguments): PipelineResult
    {
        $this->record('pipeline.import');
        if ($this->sendSigtermDuringImport) {
            posix_kill(getmypid(), SIGTERM);
        }
        $transformer = $arguments[2] ?? null;
        if ($transformer instanceof SqlStreamTransformer) {
            $transformer->push("DROP TABLE IF EXISTS `users`;\nCREATE TABLE `users` (`id` bigint);\n");
            $transformer->finish();
        }

        return new PipelineResult(100, 90, hash('sha256', 'fixture'), ['gzip' => 0, 'mysql' => 0], []);
    }

    private function record(string $operation): void
    {
        $this->calls[] = $operation;
        if ($this->failAt === $operation) {
            throw new RuntimeException($operation.' failed');
        }
    }

    private function remember(RestoreContext $context): void
    {
        $this->seenContextTokens[] = $context->restoreToken;
    }

    /** @return array<string, mixed> */
    private function statePayload(RestoreState $state): array
    {
        $token = $this->observedToken ?? self::INITIAL_TOKEN;

        return [
            'state' => $state->value,
            'tokens' => $state === RestoreState::Clean ? [] : [$token],
            'shadow_tables' => $state === RestoreState::BrokenOldSet ? ["__rst_{$token}_users"] : [],
            'old_tables' => $state === RestoreState::BrokenOldSet && $this->ambiguousBrokenOldSet
                ? ["__old_{$token}_users"]
                : [],
            'unexpected_shadow_suffixes' => [],
            'malformed_namespace_tables' => [],
        ];
    }

    private function context(string $token): RestoreContext
    {
        return new RestoreContext(
            restoreToken: $token,
            artifactSha256: str_repeat('a', 64),
            schema: ['tables' => ['users' => ['columns' => ['id' => []]]]],
            schemaAuthoritative: true,
            sourceTables: ['users'],
            businessTables: ['users'],
            runtimeResetTables: [],
            retainedLogTables: [],
            shadowTableMap: ['users' => "__rst_{$token}_users"],
            oldTableMap: ['users' => "__old_{$token}_users"],
            columnOrder: ['users' => ['id']],
            generatedColumns: ['users' => []],
            desiredForeignKeys: [],
            legacyAdjunctTables: [],
        );
    }
}
