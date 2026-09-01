<?php

declare(strict_types=1);

namespace App\Services\Backup\Restore;

use App\Models\Admin;
use App\Models\User;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class RestoreValidator
{
    private const SAMPLE_LIMIT = 20;

    public function __construct(private readonly DatabaseStructureService $structureService) {}

    public function validateShadow(RestoreContext $context): RestoreValidationReport
    {
        return $this->validate($context, $context->shadowTableMap, true);
    }

    public function validateActive(RestoreContext $context): RestoreValidationReport
    {
        $tableMap = [];
        foreach ($context->swapTables() as $table) {
            $tableMap[$table] = $table;
        }

        return $this->validate($context, $tableMap, false);
    }

    /**
     * @param  array<string, string>  $tableMap
     */
    private function validate(RestoreContext $context, array $tableMap, bool $shadow): RestoreValidationReport
    {
        $errors = [];
        $warnings = [];
        $metrics = [
            'checked_tables' => 0,
            'foreign_keys_checked' => 0,
            'model_smoke' => $shadow ? 'not_applicable' : 'skipped_schema_difference',
        ];

        try {
            $this->assertMap($context, $tableMap);
            $this->validateStructure($context, $tableMap, $shadow, $errors);
            $this->checkTables($context, $tableMap, $errors, $metrics);
            $this->checkForeignKeys($context, $tableMap, $errors, $metrics);
            $this->checkBusinessInvariants($context, $tableMap, $errors, $warnings);
            if (! $shadow && $this->programSchemaMatches($context)) {
                $this->runModelSmoke($errors, $metrics);
            }
        } catch (Throwable) {
            $errors[] = [
                'code' => 'validation_unavailable',
                'message' => '恢复数据库校验无法完成。',
            ];
        }

        return new RestoreValidationReport($errors === [], $errors, $warnings, $metrics);
    }

    /**
     * @param  array<string, string>  $tableMap
     * @param  list<array<string, mixed>>  $errors
     */
    private function validateStructure(
        RestoreContext $context,
        array $tableMap,
        bool $shadow,
        array &$errors,
    ): void {
        $connection = $this->connectionName();
        $export = $this->structureService->exportBackupStructure($connection);
        if (! $context->schemaAuthoritative) {
            $this->validateInferredStructure($context, $tableMap, $shadow, $export, $errors);

            return;
        }
        $expected = ['tables' => []];
        $actual = ['tables' => []];
        $physicalToSource = array_flip($tableMap);
        $actualShadowForeignKeys = [];

        foreach ($context->swapTables() as $source) {
            $physical = $tableMap[$source];
            $expectedTable = $context->schema['tables'][$source] ?? null;
            if (! is_array($expectedTable)) {
                throw new RuntimeException("恢复 Schema 缺少表定义: {$source}");
            }
            $actualTable = $export['tables'][$physical] ?? null;
            if (is_array($actualTable)) {
                $actualTable = $this->remapForeignKeyReferences($actualTable, $physicalToSource);
                if ($shadow) {
                    foreach ($actualTable['foreign_keys'] ?? [] as $name => $foreignKey) {
                        $actualShadowForeignKeys[$name] = ['table' => $source] + $foreignKey;
                    }
                    $actualTable['foreign_keys'] = [];
                }
                $actual['tables'][$source] = $this->normalizeForeignKeyRules($actualTable);
            }
            if ($shadow) {
                $expectedTable['foreign_keys'] = [];
            }
            $expected['tables'][$source] = $this->normalizeForeignKeyRules($expectedTable);
        }

        $actual = $this->alignRecordedColumnMetadata($expected, $actual);
        $diff = $this->structureService->compareBackupStructures($expected, $actual);
        if ($this->hasStructureDifferences($diff)) {
            $errors[] = [
                'code' => 'schema_mismatch',
                'message' => '恢复表结构与备份 Schema 不一致。',
                'tables' => array_slice(array_values(array_unique(array_merge(
                    array_keys($diff['missing_tables'] ?? []),
                    array_keys($diff['extra_tables'] ?? []),
                    array_keys($diff['table_differences'] ?? []),
                ))), 0, self::SAMPLE_LIMIT),
            ];
        }

        if ($shadow && $actualShadowForeignKeys !== []) {
            $expectedForeignKeys = $this->normalizeForeignKeyRules($context->desiredForeignKeys);
            $actualShadowForeignKeys = $this->normalizeForeignKeyRules($actualShadowForeignKeys);
            $expectedForeignKeys = $this->canonicalize($expectedForeignKeys);
            $actualShadowForeignKeys = $this->canonicalize($actualShadowForeignKeys);
            if ($actualShadowForeignKeys !== $expectedForeignKeys) {
                $errors[] = [
                    'code' => 'shadow_foreign_key_mismatch',
                    'message' => '影子表已有外键与备份定义不一致。',
                    'constraints' => array_slice(array_keys($actualShadowForeignKeys), 0, self::SAMPLE_LIMIT),
                ];
            }
        }
    }

    /**
     * 无 schema.json 时只校验能够从受控 mysqldump DDL 可靠推导的结构事实。
     *
     * @param  array<string, string>  $tableMap
     * @param  array<string, mixed>  $export
     * @param  list<array<string, mixed>>  $errors
     */
    private function validateInferredStructure(
        RestoreContext $context,
        array $tableMap,
        bool $shadow,
        array $export,
        array &$errors,
    ): void {
        $physicalToSource = array_flip($tableMap);
        $actualForeignKeys = [];
        $mismatched = [];
        foreach ($context->swapTables() as $source) {
            $actual = $export['tables'][$tableMap[$source]] ?? null;
            if (! is_array($actual)) {
                $mismatched[] = $source;

                continue;
            }
            $actualColumns = is_array($actual['columns'] ?? null) ? $actual['columns'] : [];
            $actualGenerated = array_keys(array_filter(
                $actualColumns,
                fn (array $column): bool => trim((string) ($column['generation_expression'] ?? '')) !== ''
                    || str_contains(strtoupper((string) ($column['extra'] ?? '')), 'GENERATED'),
            ));
            if (array_keys($actualColumns) !== ($context->columnOrder[$source] ?? [])
                || $actualGenerated !== ($context->generatedColumns[$source] ?? [])) {
                $mismatched[] = $source;
            }
            $expectedIndexes = $context->schema['tables'][$source]['indexes'] ?? [];
            $actualIndexes = $actual['indexes'] ?? [];
            if (! is_array($expectedIndexes) || ! is_array($actualIndexes)
                || $this->canonicalize($expectedIndexes) !== $this->canonicalize($actualIndexes)) {
                $mismatched[] = $source;
            }
            $actual = $this->remapForeignKeyReferences($actual, $physicalToSource);
            foreach ($actual['foreign_keys'] ?? [] as $name => $foreignKey) {
                $actualForeignKeys[$name] = ['table' => $source] + $foreignKey;
            }
        }
        if ($mismatched !== []) {
            $errors[] = [
                'code' => 'schema_mismatch',
                'message' => '恢复表结构与备份 SQL DDL 不一致。',
                'tables' => array_slice(array_values(array_unique($mismatched)), 0, self::SAMPLE_LIMIT),
            ];
        }

        $expectedForeignKeys = $shadow ? [] : $context->desiredForeignKeys;
        $expectedForeignKeys = $this->canonicalize($this->normalizeForeignKeyRules($expectedForeignKeys));
        $actualForeignKeys = $this->canonicalize($this->normalizeForeignKeyRules($actualForeignKeys));
        if ($expectedForeignKeys !== $actualForeignKeys) {
            $errors[] = [
                'code' => $shadow ? 'shadow_foreign_key_mismatch' : 'schema_mismatch',
                'message' => $shadow
                    ? '影子表包含备份 SQL DDL 未允许的外键。'
                    : '恢复外键与备份 SQL DDL 不一致。',
                'constraints' => array_slice(array_values(array_unique(array_merge(
                    array_keys($expectedForeignKeys),
                    array_keys($actualForeignKeys),
                ))), 0, self::SAMPLE_LIMIT),
            ];
        }
    }

    /**
     * @param  array<string, string>  $tableMap
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $metrics
     */
    private function checkTables(
        RestoreContext $context,
        array $tableMap,
        array &$errors,
        array &$metrics,
    ): void {
        foreach ($context->swapTables() as $source) {
            $rows = $this->connection()->select('CHECK TABLE '.$this->quote($tableMap[$source]));
            $metrics['checked_tables']++;
            $failed = array_filter($rows, function (object $row): bool {
                $type = strtolower((string) ($row->Msg_type ?? $row->msg_type ?? ''));
                $text = strtolower((string) ($row->Msg_text ?? $row->msg_text ?? ''));

                return $type === 'error' || ($type === 'status' && $text !== 'ok');
            });
            if ($failed !== []) {
                $errors[] = [
                    'code' => 'check_table_failed',
                    'message' => '恢复表物理检查失败。',
                    'table' => $source,
                ];
            }
        }
    }

    /**
     * @param  array<string, string>  $tableMap
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $metrics
     */
    private function checkForeignKeys(
        RestoreContext $context,
        array $tableMap,
        array &$errors,
        array &$metrics,
    ): void {
        foreach ($context->desiredForeignKeys as $name => $foreignKey) {
            $owner = $foreignKey['table'] ?? null;
            $parent = $foreignKey['references']['table'] ?? null;
            $columns = $foreignKey['columns'] ?? null;
            $referencedColumns = $foreignKey['references']['columns'] ?? null;
            if (! is_string($owner) || ! is_string($parent) || ! is_array($columns) ||
                ! is_array($referencedColumns) || count($columns) === 0 || count($columns) !== count($referencedColumns) ||
                ! isset($tableMap[$owner], $tableMap[$parent])) {
                throw new RuntimeException("恢复外键定义无效: {$name}");
            }

            $join = [];
            $present = [];
            foreach ($columns as $index => $column) {
                $column = $this->identifier($column);
                $referenced = $this->identifier($referencedColumns[$index]);
                $join[] = 'c.'.$this->quote($column).' = p.'.$this->quote($referenced);
                $present[] = 'c.'.$this->quote($column).' IS NOT NULL';
            }
            $firstReferenced = $this->identifier($referencedColumns[0]);
            $sql = 'SELECT COUNT(*) AS aggregate FROM '.$this->quote($tableMap[$owner]).' c '.
                'LEFT JOIN '.$this->quote($tableMap[$parent]).' p ON '.implode(' AND ', $join).' '.
                'WHERE '.implode(' AND ', $present).' AND p.'.$this->quote($firstReferenced).' IS NULL';
            $count = $this->count($sql);
            $metrics['foreign_keys_checked']++;
            if ($count > 0) {
                $errors[] = [
                    'code' => 'foreign_key_orphan',
                    'message' => '备份外键存在孤儿数据。',
                    'constraint' => (string) $name,
                    'count' => $count,
                ];
            }
        }
    }

    /**
     * @param  array<string, string>  $tableMap
     * @param  list<array<string, mixed>>  $errors
     * @param  list<array<string, mixed>>  $warnings
     */
    private function checkBusinessInvariants(
        RestoreContext $context,
        array $tableMap,
        array &$errors,
        array &$warnings,
    ): void {
        $this->checkAdmin($context, $tableMap, $errors);
        $this->checkUserUniqueness($context, $tableMap, $errors, $warnings);
        $this->checkTransactions($context, $tableMap, $errors);
        $this->checkFunds($context, $tableMap, $errors);
        $this->checkOrders($context, $tableMap, $errors);
    }

    /** @param list<array<string, mixed>> $errors */
    private function checkAdmin(RestoreContext $context, array $map, array &$errors): void
    {
        if (! $this->hasColumns($context, 'admins', ['username', 'password', 'status']) || ! isset($map['admins'])) {
            return;
        }
        $table = $this->quote($map['admins']);
        if ($this->count("SELECT COUNT(*) AS aggregate FROM {$table} WHERE `status` = 1 AND `username` <> '' AND `password` <> ''") === 0) {
            $errors[] = ['code' => 'admin_unusable', 'message' => '恢复数据中没有可用管理员。', 'count' => 0];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  list<array<string, mixed>>  $warnings
     */
    private function checkUserUniqueness(RestoreContext $context, array $map, array &$errors, array &$warnings): void
    {
        if (! isset($map['users'])) {
            return;
        }
        $table = $this->quote($map['users']);
        if ($this->count("SELECT COUNT(*) AS aggregate FROM {$table}") === 0) {
            $warnings[] = ['code' => 'users_empty', 'message' => '恢复数据中没有用户记录。', 'count' => 0];
        }
        foreach (['username', 'email', 'mobile'] as $column) {
            if (! $this->hasColumns($context, 'users', [$column])) {
                continue;
            }
            $identifier = $this->quote($column);
            $count = $this->count(
                "SELECT COUNT(*) AS aggregate FROM (SELECT {$identifier} FROM {$table} WHERE {$identifier} IS NOT NULL GROUP BY {$identifier} HAVING COUNT(*) > 1) duplicates",
            );
            if ($count > 0) {
                $errors[] = [
                    'code' => "user_{$column}_duplicate",
                    'message' => '恢复用户唯一字段存在重复。',
                    'field' => $column,
                    'count' => $count,
                ];
            }
        }
    }

    /** @param list<array<string, mixed>> $errors */
    private function checkTransactions(RestoreContext $context, array $map, array &$errors): void
    {
        if (! isset($map['transactions'])) {
            return;
        }
        $table = $this->quote($map['transactions']);
        if ($this->hasColumns($context, 'transactions', ['dedup_key'])) {
            $count = $this->count(
                "SELECT COUNT(*) AS aggregate FROM (SELECT `dedup_key` FROM {$table} WHERE `dedup_key` IS NOT NULL GROUP BY `dedup_key` HAVING COUNT(*) > 1) duplicates",
            );
            $this->addCountError($errors, 'transaction_dedup_duplicate', '交易生成键存在重复。', $count);
        }
        if ($this->hasColumns($context, 'transactions', ['amount', 'balance_before', 'balance_after'])) {
            $count = $this->count(
                "SELECT COUNT(*) AS aggregate FROM {$table} WHERE ABS((`balance_before` + `amount`) - `balance_after`) > 0.001",
            );
            $this->addCountError($errors, 'transaction_balance_arithmetic', '交易前后余额与金额不守恒。', $count);
        }
    }

    /** @param list<array<string, mixed>> $errors */
    private function checkFunds(RestoreContext $context, array $map, array &$errors): void
    {
        if (! isset($map['users'], $map['transactions'], $map['funds']) ||
            ! $this->hasColumns($context, 'users', ['id', 'balance']) ||
            ! $this->hasColumns($context, 'transactions', ['user_id', 'type', 'transaction_id', 'amount']) ||
            ! $this->hasColumns($context, 'funds', ['id', 'user_id', 'type', 'status', 'amount'])) {
            return;
        }
        $users = $this->quote($map['users']);
        $transactions = $this->quote($map['transactions']);
        $funds = $this->quote($map['funds']);

        $l1 = $this->count(
            "SELECT COUNT(*) AS aggregate FROM (SELECT u.`id` FROM {$users} u LEFT JOIN {$transactions} t ON t.`user_id` = u.`id` GROUP BY u.`id`, u.`balance` HAVING ABS(u.`balance` - COALESCE(SUM(t.`amount`), 0)) > 0.001) violations",
        );
        $this->addCountError($errors, 'fund_accounting_identity', '用户余额与交易累计不一致。', $l1, 'L1');

        $l2 = $this->count(
            "SELECT COUNT(*) AS aggregate FROM (SELECT `type`, `transaction_id` FROM {$transactions} WHERE `type` != 'order' GROUP BY `type`, `transaction_id` HAVING COUNT(*) > 1) violations",
        );
        $this->addCountError($errors, 'fund_event_duplicate', '非订单资金事件存在重复。', $l2, 'L2');

        $forward = $this->count(
            "SELECT COUNT(*) AS aggregate FROM {$funds} f WHERE f.`status` IN (1, 2) AND NOT EXISTS (SELECT 1 FROM {$transactions} t WHERE t.`user_id` = f.`user_id` AND t.`type` = f.`type` AND t.`transaction_id` = f.`id`)",
        );
        $reverse = $this->count(
            "SELECT COUNT(*) AS aggregate FROM {$transactions} t WHERE t.`type` IN ('addfunds', 'deduct', 'refunds', 'reverse') AND NOT EXISTS (SELECT 1 FROM {$funds} f WHERE f.`id` = t.`transaction_id` AND f.`user_id` = t.`user_id` AND f.`status` IN (1, 2))",
        );
        $this->addCountError($errors, 'fund_state_pairing', '资金记录与交易事件状态不配对。', $forward + $reverse, 'L3');

        $l4 = $this->count(
            "SELECT COUNT(*) AS aggregate FROM {$funds} f JOIN {$transactions} t ON t.`user_id` = f.`user_id` AND t.`type` = f.`type` AND t.`transaction_id` = f.`id` WHERE f.`status` IN (1, 2) AND ((f.`type` IN ('addfunds', 'reverse') AND ABS(f.`amount` - t.`amount`) > 0.001) OR (f.`type` IN ('deduct', 'refunds') AND ABS(f.`amount` + t.`amount`) > 0.001))",
        );
        $this->addCountError($errors, 'fund_amount_pairing', '资金记录与交易金额或符号不匹配。', $l4, 'L4');
    }

    /** @param list<array<string, mixed>> $errors */
    private function checkOrders(RestoreContext $context, array $map, array &$errors): void
    {
        if (! isset($map['orders'], $map['transactions']) ||
            ! $this->hasColumns($context, 'orders', ['id', 'user_id']) ||
            ! $this->hasColumns($context, 'transactions', ['user_id', 'type', 'transaction_id'])) {
            return;
        }
        $orders = $this->quote($map['orders']);
        $transactions = $this->quote($map['transactions']);
        $count = $this->count(
            "SELECT COUNT(*) AS aggregate FROM {$transactions} t WHERE t.`type` IN ('order', 'cancel') AND NOT EXISTS (SELECT 1 FROM {$orders} o WHERE o.`id` = t.`transaction_id`)",
        );
        $this->addCountError($errors, 'order_transaction_orphan', '订单交易未关联到存在的订单。', $count);
    }

    /** @param list<array<string, mixed>> $errors */
    private function runModelSmoke(array &$errors, array &$metrics): void
    {
        try {
            Admin::query()->limit(1)->get(['id']);
            User::query()->withoutGlobalScopes()->limit(1)->get(['id']);
            $metrics['model_smoke'] = 'passed';
        } catch (Throwable) {
            $metrics['model_smoke'] = 'failed';
            $errors[] = ['code' => 'model_smoke_failed', 'message' => '当前程序模型只读检查失败。'];
        }
    }

    private function programSchemaMatches(RestoreContext $context): bool
    {
        $path = base_path('database/structure.json');
        if (! File::exists($path)) {
            return false;
        }
        $program = json_decode(File::get($path), true);
        if (! is_array($program)) {
            return false;
        }
        foreach ($context->retainedLogTables as $table) {
            unset($program['tables'][$table]);
        }
        $backup = $this->alignRecordedColumnMetadata($program, $context->schema);
        $diff = $this->structureService->compareStructures(
            $this->normalizeForeignKeyRules($program),
            $this->normalizeForeignKeyRules($backup),
        );

        return ($diff['missing_tables'] ?? []) === [] && ($diff['table_differences'] ?? []) === [];
    }

    private function hasColumns(RestoreContext $context, string $table, array $columns): bool
    {
        $available = $context->schema['tables'][$table]['columns'] ?? [];

        return is_array($available) && array_diff($columns, array_keys($available)) === [];
    }

    /** @param list<array<string, mixed>> $errors */
    private function addCountError(
        array &$errors,
        string $code,
        string $message,
        int $count,
        ?string $layer = null,
    ): void {
        if ($count === 0) {
            return;
        }
        $error = ['code' => $code, 'message' => $message, 'count' => $count];
        if ($layer !== null) {
            $error['layer'] = $layer;
        }
        $errors[] = $error;
    }

    private function count(string $sql): int
    {
        return (int) ($this->connection()->selectOne($sql)->aggregate ?? 0);
    }

    private function connection(): Connection
    {
        return DB::connection($this->connectionName());
    }

    private function connectionName(): string
    {
        return (string) Config::get('database.default');
    }

    /** @param array<string, string> $tableMap */
    private function assertMap(RestoreContext $context, array $tableMap): void
    {
        foreach ($context->swapTables() as $source) {
            $this->identifier($source);
            if (! isset($tableMap[$source])) {
                throw new RuntimeException("恢复表缺少受控映射: {$source}");
            }
            $this->identifier($tableMap[$source]);
        }
    }

    private function identifier(mixed $identifier): string
    {
        if (! is_string($identifier) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new RuntimeException('恢复校验包含无效数据库标识符');
        }

        return $identifier;
    }

    private function quote(string $identifier): string
    {
        return '`'.$this->identifier($identifier).'`';
    }

    private function hasStructureDifferences(array $diff): bool
    {
        return ($diff['missing_tables'] ?? []) !== [] ||
            ($diff['extra_tables'] ?? []) !== [] ||
            ($diff['table_differences'] ?? []) !== [];
    }

    /** @param array<string, string> $physicalToSource */
    private function remapForeignKeyReferences(array $table, array $physicalToSource): array
    {
        if (! is_array($table['foreign_keys'] ?? null)) {
            return $table;
        }
        foreach ($table['foreign_keys'] as &$foreignKey) {
            $reference = $foreignKey['references']['table'] ?? null;
            if (is_string($reference) && isset($physicalToSource[$reference])) {
                $foreignKey['references']['table'] = $physicalToSource[$reference];
            }
        }
        unset($foreignKey);

        return $table;
    }

    private function normalizeForeignKeyRules(array $value): array
    {
        array_walk_recursive($value, function (&$item, $key): void {
            if (in_array($key, ['on_delete', 'on_update'], true) && is_string($item) &&
                in_array(strtoupper($item), ['RESTRICT', 'NO ACTION'], true)) {
                $item = 'NO ACTION';
            }
        });

        return $value;
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function alignRecordedColumnMetadata(array $expected, array $actual): array
    {
        foreach ($expected['tables'] ?? [] as $table => $definition) {
            foreach ($definition['columns'] ?? [] as $column => $columnDefinition) {
                foreach (['character_set', 'generation_expression'] as $field) {
                    if (! array_key_exists($field, $columnDefinition)) {
                        unset($actual['tables'][$table]['columns'][$column][$field]);
                    }
                }
            }
        }

        return $actual;
    }
}
