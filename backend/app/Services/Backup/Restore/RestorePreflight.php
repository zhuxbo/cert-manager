<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use App\Services\Backup\BackupArtifactInspector;
use App\Services\Backup\BackupService;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\SqlDumpRewritePlan;
use App\Services\Backup\SqlDumpRewriter;
use App\Services\Upgrade\DatabaseStructureService;
use RuntimeException;
use Throwable;

final class RestorePreflight
{
    private const SQL_CHUNK_BYTES = 1048576;

    public function __construct(
        private readonly BackupArtifactInspector $artifactInspector,
        private readonly MysqlToolchainChecker $toolchainChecker,
        private readonly DatabaseStructureService $structureService,
        private readonly BackupService $backupService,
        private readonly RestoreStateInspector $stateInspector,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(RestoreRequest $request): array
    {
        $report = $this->emptyReport($request);

        try {
            $artifact = $this->artifactInspector->inspect($request->backupId);
        } catch (Throwable $e) {
            $message = $request->backupId === ''
                ? $e->getMessage()
                : str_replace($request->backupId, '[backup-id]', $e->getMessage());
            $this->addBlocker($report, 'artifact_invalid', $this->safeMessage($message));

            return $this->finalize($report, $request);
        }

        $report['artifact'] = [
            'id' => $artifact['id'],
            'legacy' => $artifact['legacy'],
            'sql' => basename($artifact['sql_path']),
            'schema' => $artifact['schema_path'] === null ? null : basename($artifact['schema_path']),
            'integrity' => $artifact['integrity'],
        ];

        try {
            $toolchain = $this->toolchainChecker->inspect(requireMysql: true, requireMysqldump: false);
            $report['toolchain'] = $this->toolchainFacts($toolchain);
            if (! $toolchain['supported']) {
                $this->addBlocker(
                    $report,
                    'toolchain_unsupported',
                    implode('；', array_map($this->safeMessage(...), $toolchain['errors'])),
                );
            }
        } catch (Throwable $e) {
            $toolchain = null;
            $this->addBlocker($report, 'toolchain_unsupported', $this->safeMessage($e->getMessage()));
        }

        $metadata = $artifact['metadata'];
        $report['versions'] = $this->versionFacts($metadata, $toolchain);

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.$connection.database");
        try {
            $current = $this->structureService->exportBackupStructure($connection);
            $retainedLogs = $this->backupService->resolveRetainedTables($database);
            $currentComparable = $this->backupService->filterStructureTables($current, $retainedLogs);
        } catch (Throwable $e) {
            $this->addBlocker($report, 'current_schema_unavailable', $this->safeMessage($e->getMessage()));

            return $this->finalize($report, $request);
        }

        $inferredEncountered = null;
        if ($artifact['schema'] === null) {
            try {
                [$inferredSchema, $inferredEncountered] = $this->inferSqlSchema($artifact['sql_path']);
                $artifact['schema'] = $inferredSchema;
                $artifact['schema_inferred'] = true;
            } catch (Throwable $e) {
                $this->addBlocker($report, 'sql_unsafe', $this->safeMessage($e->getMessage()));
                $report['space'] = $this->spaceFacts([], [], $current);

                return $this->finalize($report, $request);
            }
        }

        try {
            $restoreToken = bin2hex(random_bytes(6));
            [$schema, $authoritative, $sourceTables, $runtimeTables, $legacyAdjuncts] =
                $this->resolveSchema($artifact, $currentComparable, $report);
            $context = $this->buildContext(
                $restoreToken,
                (string) $artifact['integrity']['sha256'],
                $schema,
                $authoritative,
                $sourceTables,
                $runtimeTables,
                $retainedLogs,
                $legacyAdjuncts,
            );
            $report['context'] = $context;
        } catch (Throwable $e) {
            $message = $this->safeMessage($e->getMessage());
            $code = str_contains($message, '无效普通表名') || str_contains($message, '前缀后的表名')
                ? 'invalid_identifier'
                : 'schema_invalid';
            $this->addBlocker($report, $code, $message);
            $report['space'] = $this->spaceFacts([], [], $current);

            return $this->finalize($report, $request);
        }

        if ($artifact['legacy']) {
            try {
                $encountered = $inferredEncountered ?? $this->scanSql($artifact['sql_path'], $context);
                if (in_array('migrations', $legacyAdjuncts, true)) {
                    if (in_array('migrations', $encountered, true)) {
                        $this->addWarning(
                            $report,
                            'legacy_migrations_adjunct',
                            '遗留 SQL 包含 Schema 缺失的 migrations，已仅按固定 Laravel migrations 定义纳入上下文。',
                        );
                    } else {
                        unset($schema['tables']['migrations']);
                        $sourceTables = array_values(array_diff($sourceTables, ['migrations']));
                        $legacyAdjuncts = [];
                        $context = $this->buildContext(
                            $restoreToken,
                            (string) $artifact['integrity']['sha256'],
                            $schema,
                            $authoritative,
                            $sourceTables,
                            $runtimeTables,
                            $retainedLogs,
                            $legacyAdjuncts,
                        );
                        $report['context'] = $context;
                    }
                }
                if ($authoritative) {
                    $missingSqlTables = array_values(array_diff($context->sourceTables, $encountered));
                    if ($missingSqlTables !== []) {
                        $this->addBlocker(
                            $report,
                            'sql_table_set_mismatch',
                            'SQL 未包含 Schema 声明的全部源表。',
                            ['missing_tables' => $missingSqlTables],
                        );
                    }
                }
            } catch (Throwable $e) {
                $this->addBlocker($report, 'sql_unsafe', $this->safeMessage($e->getMessage()));
            }
        }

        try {
            $diff = $authoritative
                ? $this->structureService->compareBackupStructures($schema, $currentComparable)
                : $this->compareInferredStructures($schema, $currentComparable);
            $diffSummary = $this->diffSummary($diff);
        } catch (Throwable $e) {
            $diffSummary = $this->diffSummary([]);
            $this->addBlocker($report, 'schema_invalid', $this->safeMessage($e->getMessage()));
        }
        $report['schema'] = [
            'authoritative' => $authoritative,
            'diff' => $diffSummary,
        ];
        if ($diffSummary['has_difference']) {
            $this->addConfirmation(
                $report,
                'schema_difference',
                '备份 Schema 与当前数据库结构存在语义差异。',
            );
        }

        try {
            $state = $this->stateInspector->inspect($context);
            $report['state'] = $state;
            if (($state['state'] ?? null) !== RestoreState::Clean->value) {
                $this->addBlocker(
                    $report,
                    'restore_state_not_clean',
                    '检测到未清理的恢复命名空间，当前预检不允许启动新的恢复。',
                    ['state' => $state['state'] ?? 'unknown'],
                );
            }
        } catch (Throwable $e) {
            $this->addBlocker($report, 'restore_state_unavailable', $this->safeMessage($e->getMessage()));
        }

        $this->inspectForeignKeys($current, $context, $report);

        $report['space'] = $this->spaceFacts($schema, $metadata, $current, $context->sourceTables);

        return $this->finalize($report, $request);
    }

    public function assertRunnable(array $report, RestoreRequest $request): void
    {
        $blockers = $report['hard_blockers'] ?? null;
        $confirmations = $report['confirmations'] ?? null;
        if (! is_array($blockers) || ! is_array($confirmations)) {
            throw new RuntimeException('恢复预检报告格式无效');
        }
        if ($blockers !== []) {
            throw new RuntimeException($this->messages($blockers, '恢复预检存在硬阻断'));
        }
        if ($confirmations !== [] && ! $request->allowSchemaDifference) {
            throw new RuntimeException($this->messages($confirmations, '恢复预检需要确认 Schema 差异'));
        }

        foreach ($confirmations as $confirmation) {
            if (! in_array($confirmation['code'] ?? null, ['schema_difference', 'schema_not_authoritative'], true)) {
                throw new RuntimeException('恢复预检包含不可绕过的确认项');
            }
        }
    }

    /** @return array<string, mixed> */
    private function emptyReport(RestoreRequest $request): array
    {
        $artifactId = $this->safeArtifactId($request->backupId);

        return [
            'runnable' => false,
            'hard_blockers' => [],
            'confirmations' => [],
            'warnings' => [],
            'artifact' => [
                'id' => $artifactId,
                'legacy' => null,
                'sql' => $artifactId === null ? null : $artifactId.'.sql.gz',
                'schema' => null,
                'integrity' => ['verified' => false],
            ],
            'toolchain' => ['supported' => false, 'errors' => [], 'warnings' => []],
            'versions' => [
                'backup_application' => null,
                'current_application' => $this->currentApplicationFacts(),
                'backup_toolchain' => null,
                'current_server' => null,
                'current_mysql_client' => null,
            ],
            'schema' => [
                'authoritative' => false,
                'diff' => $this->diffSummary([]),
            ],
            'space' => $this->spaceFacts([], [], []),
            'state' => ['state' => 'unknown'],
            'context' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $currentSchema
     * @param  array<string, mixed>  $report
     * @return array{array<string,mixed>, bool, list<string>, list<string>, list<string>}
     */
    private function resolveSchema(array $artifact, array $currentSchema, array &$report): array
    {
        $artifactSchema = $artifact['schema'];
        if (($artifact['schema_inferred'] ?? false) === true) {
            if (! is_array($artifactSchema['tables'] ?? null) || $artifactSchema['tables'] === []) {
                throw new RuntimeException('无法从备份 SQL 推导表结构。');
            }
            $schema = $artifactSchema;
            $sourceTables = array_keys($schema['tables']);
            foreach (BackupService::RUNTIME_RESET_TABLES as $table) {
                if (is_array($currentSchema['tables'][$table] ?? null)) {
                    $schema['tables'][$table] = $currentSchema['tables'][$table];
                    $sourceTables = array_values(array_diff($sourceTables, [$table]));
                }
            }
            $runtimeTables = $this->runtimeTables($schema);
            $this->addWarning(
                $report,
                'schema_low_guarantee',
                '备份缺少 Schema，已从 SQL DDL 推导低保证恢复结构。',
            );
            $this->addConfirmation(
                $report,
                'schema_not_authoritative',
                '备份缺少权威 Schema，必须显式确认后才能继续。',
            );

            return [$schema, false, $sourceTables, $runtimeTables, []];
        }
        if ($artifactSchema === null) {
            throw new RuntimeException('备份 Schema 状态无效。');
        }
        if (! is_array($artifactSchema['tables'] ?? null)) {
            throw new RuntimeException('备份 Schema 缺少 tables。');
        }

        $schema = $artifactSchema;
        if (! $artifact['legacy']) {
            $runtimeTables = $this->runtimeTables($schema);
            $included = $artifact['metadata']['included_tables'] ?? null;
            if (! is_array($included)
                || ! array_is_list($included)) {
                throw new RuntimeException('新版备份 metadata.included_tables 无效。');
            }
            foreach ($included as $table) {
                if (! is_string($table) || ! array_key_exists($table, $schema['tables'])) {
                    throw new RuntimeException('新版备份 metadata.included_tables 包含 Schema 外表。');
                }
            }
            if (count($included) !== count(array_unique($included))) {
                throw new RuntimeException('新版备份 metadata.included_tables 包含重复表。');
            }
            if (array_intersect($included, $runtimeTables) !== []) {
                throw new RuntimeException('运行时重置表不能出现在 metadata.included_tables。');
            }
            $unclassified = array_values(array_diff(
                array_keys($schema['tables']),
                $included,
                $runtimeTables,
            ));
            if ($unclassified !== []) {
                throw new RuntimeException('新版备份 Schema 包含未归类为 SQL 或运行时重置的表。');
            }

            return [$schema, true, $included, $runtimeTables, []];
        }

        foreach (BackupService::RUNTIME_RESET_TABLES as $table) {
            if (! array_key_exists($table, $schema['tables'])
                && is_array($currentSchema['tables'][$table] ?? null)) {
                $schema['tables'][$table] = $currentSchema['tables'][$table];
            }
        }
        $runtimeTables = $this->runtimeTables($schema);
        $sourceTables = array_values(array_diff(array_keys($schema['tables']), $runtimeTables));
        $legacyAdjuncts = [];
        if (! array_key_exists('migrations', $schema['tables'])) {
            $schema['tables']['migrations'] = $this->legacyMigrationsSchema();
            $sourceTables[] = 'migrations';
            $legacyAdjuncts[] = 'migrations';
        }
        $this->addWarning($report, 'legacy_artifact', '遗留备份缺少新版 metadata 完整事实。');

        return [$schema, true, $sourceTables, $runtimeTables, $legacyAdjuncts];
    }

    /**
     * @param  list<string>  $sourceTables
     * @param  list<string>  $runtimeTables
     * @param  list<string>  $retainedLogs
     * @param  list<string>  $legacyAdjuncts
     */
    private function buildContext(
        string $token,
        string $artifactSha256,
        array $schema,
        bool $authoritative,
        array $sourceTables,
        array $runtimeTables,
        array $retainedLogs,
        array $legacyAdjuncts,
    ): RestoreContext {
        $swapTables = array_values(array_unique(array_merge($sourceTables, $runtimeTables)));
        $shadowMap = [];
        $oldMap = [];
        $columnOrder = [];
        $generatedColumns = [];
        $desiredForeignKeys = [];

        foreach (array_keys($schema['tables'] ?? []) as $table) {
            $this->assertTableIdentifier((string) $table, $token);
        }
        foreach ($swapTables as $table) {
            $tableSchema = $schema['tables'][$table] ?? null;
            if (! is_array($tableSchema) || ! is_array($tableSchema['columns'] ?? null)) {
                throw new RuntimeException("Schema 表缺少列定义: $table");
            }
            $shadowMap[$table] = "__rst_{$token}_{$table}";
            $oldMap[$table] = "__old_{$token}_{$table}";
            $columnOrder[$table] = array_keys($tableSchema['columns']);
            $generatedColumns[$table] = array_keys(array_filter(
                $tableSchema['columns'],
                fn (array $column): bool => trim((string) ($column['generation_expression'] ?? '')) !== ''
                    || str_contains(strtoupper((string) ($column['extra'] ?? '')), 'GENERATED'),
            ));
        }

        foreach ($schema['tables'] as $table => $tableSchema) {
            if (! in_array($table, $swapTables, true)) {
                continue;
            }
            foreach ($tableSchema['foreign_keys'] ?? [] as $name => $foreignKey) {
                $referenced = $foreignKey['references']['table'] ?? null;
                if (! is_string($referenced) || ! in_array($referenced, $swapTables, true)) {
                    throw new RuntimeException("备份 Schema 外键跨越恢复表范围: $table.$name");
                }
                if (array_key_exists($name, $desiredForeignKeys)) {
                    throw new RuntimeException("备份 Schema 外键名称重复: $name");
                }
                $desiredForeignKeys[$name] = ['table' => $table] + $foreignKey;
            }
        }

        return new RestoreContext(
            restoreToken: $token,
            artifactSha256: strtolower($artifactSha256),
            schema: $schema,
            schemaAuthoritative: $authoritative,
            sourceTables: $sourceTables,
            businessTables: array_values(array_diff($sourceTables, $legacyAdjuncts)),
            runtimeResetTables: $runtimeTables,
            retainedLogTables: $retainedLogs,
            shadowTableMap: $shadowMap,
            oldTableMap: $oldMap,
            columnOrder: $columnOrder,
            generatedColumns: $generatedColumns,
            desiredForeignKeys: $desiredForeignKeys,
            legacyAdjunctTables: $legacyAdjuncts,
        );
    }

    private function assertTableIdentifier(string $table, string $token): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException("Schema 包含无效普通表名: $table");
        }
        if (strlen("__rst_{$token}_{$table}") > 64 || strlen("__old_{$token}_{$table}") > 64) {
            throw new RuntimeException("恢复前缀后的表名超过 MySQL 64 字节限制: $table");
        }
    }

    /** @return list<string> */
    private function runtimeTables(array $schema): array
    {
        return array_values(array_filter(
            BackupService::RUNTIME_RESET_TABLES,
            fn (string $table): bool => array_key_exists($table, $schema['tables'] ?? []),
        ));
    }

    /** @return array<string, mixed> */
    private function legacyMigrationsSchema(): array
    {
        return [
            'engine' => 'InnoDB',
            'collation' => null,
            'comment' => '',
            'auto_increment' => null,
            'data_length' => 0,
            'index_length' => 0,
            'columns' => [
                'id' => $this->legacyColumn(1, 'int unsigned', 'auto_increment'),
                'migration' => $this->legacyColumn(2, 'varchar(255)'),
                'batch' => $this->legacyColumn(3, 'int'),
            ],
            'indexes' => [
                'PRIMARY' => [
                    'unique' => true,
                    'type' => 'BTREE',
                    'columns' => ['id'],
                    'sub_parts' => [null],
                ],
            ],
            'foreign_keys' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function legacyColumn(int $position, string $type, string $extra = ''): array
    {
        return [
            'position' => $position,
            'type' => $type,
            'nullable' => false,
            'default' => null,
            'extra' => $extra,
            'comment' => '',
            'character_set' => str_starts_with($type, 'varchar') ? 'utf8mb4' : null,
            'generation_expression' => '',
        ];
    }

    /** @return list<string> */
    private function scanSql(string $sqlPath, RestoreContext $context): array
    {
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

        $stream = gzopen($sqlPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法打开备份 gzip 流。');
        }
        try {
            while (! gzeof($stream)) {
                $chunk = gzread($stream, self::SQL_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('读取备份 gzip 流失败。');
                }
                $rewriter->push($chunk);
            }
            $rewriter->finish();
        } finally {
            gzclose($stream);
        }

        return $rewriter->encounteredTables();
    }

    /** @return array{array<string, mixed>, list<string>} */
    private function inferSqlSchema(string $sqlPath): array
    {
        $rewriter = new SqlDumpRewriter(new SqlDumpRewritePlan([], [], [], inferSchema: true));
        $stream = gzopen($sqlPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法打开备份 gzip 流。');
        }
        try {
            while (! gzeof($stream)) {
                $chunk = gzread($stream, self::SQL_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('读取备份 gzip 流失败。');
                }
                $rewriter->push($chunk);
            }
            $rewriter->finish();
        } finally {
            gzclose($stream);
        }

        $schema = $rewriter->inferredSchema();
        $tables = array_keys($schema['tables']);
        $withoutCreate = array_values(array_diff($rewriter->encounteredTables(), $tables));
        if ($withoutCreate !== []) {
            throw new RuntimeException('备份 SQL 引用了没有 CREATE TABLE 的表。');
        }

        return [$schema, $tables];
    }

    /** @return array{missing_tables:array, extra_tables:array, table_differences:array} */
    private function compareInferredStructures(array $backup, array $current): array
    {
        $backupTables = is_array($backup['tables'] ?? null) ? $backup['tables'] : [];
        $currentTables = is_array($current['tables'] ?? null) ? $current['tables'] : [];
        $diff = [
            'missing_tables' => array_diff_key($backupTables, $currentTables),
            'extra_tables' => array_diff_key($currentTables, $backupTables),
            'table_differences' => [],
        ];
        foreach (array_intersect(array_keys($backupTables), array_keys($currentTables)) as $table) {
            $backupColumns = is_array($backupTables[$table]['columns'] ?? null)
                ? $backupTables[$table]['columns']
                : [];
            $currentColumns = is_array($currentTables[$table]['columns'] ?? null)
                ? $currentTables[$table]['columns']
                : [];
            if (array_keys($backupColumns) !== array_keys($currentColumns)) {
                $diff['table_differences'][$table] = ['columns' => true];

                continue;
            }
            foreach ($backupColumns as $column => $definition) {
                $backupType = $this->normalizeInferredType((string) ($definition['type'] ?? ''));
                $currentType = $this->normalizeInferredType((string) ($currentColumns[$column]['type'] ?? ''));
                $backupGenerated = trim((string) ($definition['generation_expression'] ?? '')) !== '';
                $currentGenerated = trim((string) ($currentColumns[$column]['generation_expression'] ?? '')) !== ''
                    || str_contains(strtoupper((string) ($currentColumns[$column]['extra'] ?? '')), 'GENERATED');
                if ($backupType !== $currentType || $backupGenerated !== $currentGenerated) {
                    $diff['table_differences'][$table] = ['columns' => true];

                    break;
                }
            }
            if (isset($diff['table_differences'][$table])) {
                continue;
            }
            foreach (['indexes', 'foreign_keys'] as $field) {
                $backupDefinition = $this->canonicalizeInferredStructure($backupTables[$table][$field] ?? []);
                $currentDefinition = $this->canonicalizeInferredStructure($currentTables[$table][$field] ?? []);
                if ($backupDefinition !== $currentDefinition) {
                    $diff['table_differences'][$table] = [$field => true];

                    break;
                }
            }
        }

        return $diff;
    }

    private function normalizeInferredType(string $type): string
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($type)) ?? trim($type));

        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $normalized)
            ?? $normalized;
    }

    private function canonicalizeInferredStructure(mixed $value, ?string $key = null): mixed
    {
        if (is_string($value) && in_array($key, ['on_delete', 'on_update'], true)) {
            return strtoupper($value) === 'NO ACTION' ? 'RESTRICT' : strtoupper($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $itemKey => &$item) {
            $item = $this->canonicalizeInferredStructure($item, is_string($itemKey) ? $itemKey : null);
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param array<string, mixed> $report */
    private function inspectForeignKeys(array $current, RestoreContext $context, array &$report): void
    {
        $swap = $context->swapTables();
        $desiredNames = array_fill_keys(array_keys($context->desiredForeignKeys), true);
        foreach ($current['tables'] ?? [] as $table => $tableSchema) {
            foreach ($tableSchema['foreign_keys'] ?? [] as $name => $foreignKey) {
                $referenced = (string) ($foreignKey['references']['table'] ?? '');
                $ownerInSwap = in_array($table, $swap, true);
                $referencedInSwap = in_array($referenced, $swap, true);
                if ($ownerInSwap xor $referencedInSwap) {
                    $this->addBlocker(
                        $report,
                        'cross_range_foreign_key',
                        "当前物理外键跨越恢复表范围: $table.$name -> $referenced",
                    );
                }
                $state = $report['state'] ?? [];
                $recognizedShadow = in_array($state['state'] ?? null, [
                    RestoreState::ActiveForeignKeysRemoved->value, RestoreState::ShadowForeignKeysReady->value,
                ], true)
                    && ($state['shadow_foreign_keys'][$name]['table'] ?? null) === $table
                    && ! in_array($name, $state['unexpected_shadow_foreign_keys'] ?? [], true);
                if (! $ownerInSwap && isset($desiredNames[$name]) && ! $recognizedShadow) {
                    $this->addBlocker(
                        $report,
                        'foreign_key_name_conflict',
                        "恢复目标外键名已被范围外表占用: $table.$name",
                    );
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function diffSummary(array $diff): array
    {
        $changed = array_keys($diff['table_differences'] ?? []);
        sort($changed);
        $missing = array_keys($diff['missing_tables'] ?? []);
        sort($missing);
        $extra = array_keys($diff['extra_tables'] ?? []);
        sort($extra);

        return [
            'has_difference' => $missing !== [] || $extra !== [] || $changed !== [],
            'missing_tables' => $missing,
            'extra_tables' => $extra,
            'changed_tables' => $changed,
        ];
    }

    /** @return array<string, mixed> */
    private function spaceFacts(
        array $schema,
        array $metadata,
        array $current,
        array $sourceTables = [],
    ): array {
        $backupBytes = 0;
        $metadataCapacities = is_array($metadata['table_capacities'] ?? null)
            ? $metadata['table_capacities']
            : [];
        foreach ($sourceTables as $table) {
            $capacity = $metadataCapacities[$table] ?? $schema['tables'][$table] ?? [];
            $backupBytes += (int) ($capacity['data_length'] ?? 0) + (int) ($capacity['index_length'] ?? 0);
        }

        $currentBytes = 0;
        foreach ($current['tables'] ?? [] as $table) {
            $currentBytes += (int) ($table['data_length'] ?? 0) + (int) ($table['index_length'] ?? 0);
        }

        return [
            'backup_data_and_indexes_bytes' => $backupBytes,
            'current_tables_retained_bytes' => $currentBytes,
            'streaming_temp_bytes' => 0,
            'total_estimated_footprint_bytes' => $backupBytes + $currentBytes,
            'available_bytes' => null,
            'verified' => false,
            'note' => '未测量远程 MySQL 主机的可用磁盘空间；容量值仅来自 Schema 与 information_schema 估算。',
        ];
    }

    /** @return array<string, mixed> */
    private function toolchainFacts(array $toolchain): array
    {
        return [
            'supported' => (bool) $toolchain['supported'],
            'errors' => array_map($this->safeMessage(...), $toolchain['errors']),
            'warnings' => array_map($this->safeMessage(...), $toolchain['warnings']),
            'server' => $toolchain['server'],
            'mysql' => $this->mysqlComponentFacts($toolchain['mysql']),
            'gzip' => ['version' => $toolchain['gzip']['version']],
        ];
    }

    private function mysqlComponentFacts(?array $component): ?array
    {
        if ($component === null) {
            return null;
        }

        return [
            'vendor' => $component['vendor'],
            'version' => $component['version'],
            'series' => $component['series'],
        ];
    }

    /** @return array<string, mixed> */
    private function versionFacts(array $metadata, ?array $toolchain): array
    {
        return [
            'backup_application' => is_array($metadata['application'] ?? null)
                ? $metadata['application']
                : null,
            'current_application' => $this->currentApplicationFacts(),
            'backup_toolchain' => is_array($metadata['toolchain'] ?? null)
                ? $metadata['toolchain']
                : null,
            'current_server' => $toolchain['server'] ?? null,
            'current_mysql_client' => $this->mysqlComponentFacts($toolchain['mysql'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function currentApplicationFacts(): array
    {
        return [
            'version' => config('version.version'),
            'channel' => config('version.channel'),
            'build_commit' => config('version.build_commit'),
        ];
    }

    /** @param array<string, mixed> $report */
    private function addBlocker(array &$report, string $code, string $message, array $facts = []): void
    {
        $report['hard_blockers'][] = compact('code', 'message', 'facts');
    }

    /** @param array<string, mixed> $report */
    private function addConfirmation(array &$report, string $code, string $message): void
    {
        $report['confirmations'][] = compact('code', 'message');
    }

    /** @param array<string, mixed> $report */
    private function addWarning(array &$report, string $code, string $message): void
    {
        $report['warnings'][] = compact('code', 'message');
    }

    /** @return array<string, mixed> */
    private function finalize(array $report, RestoreRequest $request): array
    {
        $confirmationsAccepted = true;
        foreach ($report['confirmations'] as $confirmation) {
            if (! $request->allowSchemaDifference
                || ! in_array($confirmation['code'] ?? null, ['schema_difference', 'schema_not_authoritative'], true)) {
                $confirmationsAccepted = false;
                break;
            }
        }
        $report['runnable'] = $report['hard_blockers'] === [] && $confirmationsAccepted;

        return $report;
    }

    private function messages(array $items, string $fallback): string
    {
        $messages = array_values(array_filter(array_map(
            fn (array $item): string => (string) ($item['message'] ?? ''),
            $items,
        )));

        return $messages === [] ? $fallback : implode('；', $messages);
    }

    private function safeMessage(string $message): string
    {
        if (preg_match('//u', $message) !== 1) {
            return '预检失败。';
        }
        $password = (string) config('database.connections.'.config('database.default').'.password');
        if ($password !== '') {
            $message = str_replace($password, '[redacted]', $message);
        }
        $sanitized = preg_replace('~(?<![A-Za-z0-9_])/(?:[^\s，；,]+)~u', '[path]', $message);

        return is_string($sanitized) ? $sanitized : '预检失败。';
    }

    private function safeArtifactId(string $backupId): ?string
    {
        return preg_match('/^[a-z_]+_\d{8}_\d{6}$/D', $backupId) === 1 ? $backupId : null;
    }
}
